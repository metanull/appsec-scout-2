<?php

namespace App\Filament\Resources;

use App\Context\Inference\InferenceSuggestionReviewService;
use App\Filament\Resources\InferenceSuggestionResource\Pages\ListInferenceSuggestions;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class InferenceSuggestionResource extends Resource
{
    protected static ?string $model = InferenceSuggestion::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    protected static string|\UnitEnum|null $navigationGroup = 'Plan';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Inference review';

    public static function canViewAny(): bool
    {
        return self::canReview();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('created_at'))
            ->groups([
                Group::make('subject_type')
                    ->label('Entity type')
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (InferenceSuggestion $record): string => class_basename((string) $record->subject_type)),
            ])
            ->defaultGroup('subject_type')
            ->columns([
                TextColumn::make('suggestion_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::suggestionTypeLabel($state)),
                TextColumn::make('subject_label')
                    ->label('Subject')
                    ->state(fn (InferenceSuggestion $record): string => self::modelLabel($record->subject, (string) $record->subject_type, $record->subject_id))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('target_label')
                    ->label('Target')
                    ->state(fn (InferenceSuggestion $record): string => self::modelLabel($record->target, $record->target_type, $record->target_id))
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        (float) $state >= 0.95 => 'success',
                        (float) $state >= 0.90 => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (InferenceSuggestion $record): string => self::evidenceReason($record))
                    ->wrap()
                    ->grow(),
                TextColumn::make('source_values')
                    ->label('Source values')
                    ->state(fn (InferenceSuggestion $record): string => self::sourceValues($record))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (InferenceSuggestionStatus|string $state): string => match ($state instanceof InferenceSuggestionStatus ? $state : InferenceSuggestionStatus::from((string) $state)) {
                        InferenceSuggestionStatus::Pending => 'warning',
                        InferenceSuggestionStatus::Accepted => 'success',
                        InferenceSuggestionStatus::Rejected => 'danger',
                        InferenceSuggestionStatus::Superseded => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('suggestion_type')
                    ->label('Type')
                    ->options(self::suggestionTypeOptions()),
                Filter::make('confidence_band')
                    ->label('Confidence')
                    ->form([
                        Select::make('band')
                            ->label('Confidence band')
                            ->options([
                                'high' => 'High (>= 0.95)',
                                'medium' => 'Medium (0.90 - 0.9499)',
                                'lower' => 'Lower (< 0.90)',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        /** @var Builder<InferenceSuggestion> $query */
                        $band = $data['band'] ?? null;

                        return match ($band) {
                            'high' => $query->where('confidence', '>=', 0.95),
                            'medium' => $query->where('confidence', '>=', 0.90)->where('confidence', '<', 0.95),
                            'lower' => $query->where('confidence', '<', 0.90),
                            default => $query,
                        };
                    }),
                Filter::make('source')
                    ->form([
                        Select::make('source')
                            ->label('Source')
                            ->options([
                                'azdo' => 'AzDO',
                                'jira' => 'Jira',
                                'github' => 'GitHub',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        /** @var Builder<InferenceSuggestion> $query */
                        $source = $data['source'] ?? null;

                        return match ($source) {
                            'azdo' => $query->where('evidence', 'like', '%azdo.%'),
                            'jira' => $query->where('evidence', 'like', '%jira%'),
                            'github' => $query->where('evidence', 'like', '%github%'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('subject_type')
                    ->label('Entity')
                    ->options([
                        SoftwareSystem::class => 'Software system',
                        SecurityContainer::class => 'Security container',
                    ]),
            ])
            ->actions([
                Action::make('accept')
                    ->label('Accept')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (InferenceSuggestion $record): bool => self::isPending($record))
                    ->action(function (InferenceSuggestion $record): void {
                        self::reviewService()->accept($record, self::reviewer());

                        Notification::make()
                            ->title('Suggestion accepted')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->form([
                        Textarea::make('review_note')
                            ->label('Review note')
                            ->required()
                            ->minLength(5)
                            ->rows(3),
                    ])
                    ->visible(fn (InferenceSuggestion $record): bool => self::isPending($record))
                    ->action(function (InferenceSuggestion $record, array $data): void {
                        self::reviewService()->reject($record, self::reviewer(), (string) ($data['review_note'] ?? ''));

                        Notification::make()
                            ->title('Suggestion rejected')
                            ->success()
                            ->send();
                    }),
                Action::make('editBeforeAccept')
                    ->label('Edit before accept')
                    ->color('info')
                    ->form(fn (InferenceSuggestion $record): array => self::editBeforeAcceptForm($record))
                    ->fillForm(fn (InferenceSuggestion $record): array => self::editBeforeAcceptDefaults($record))
                    ->visible(fn (InferenceSuggestion $record): bool => self::isPending($record))
                    ->action(function (InferenceSuggestion $record, array $data): void {
                        $input = array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');

                        self::reviewService()->accept($record, self::reviewer(), $input);

                        Notification::make()
                            ->title('Suggestion accepted with edits')
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInferenceSuggestions::route('/'),
        ];
    }

    /** @return array<string, string> */
    protected static function statusOptions(): array
    {
        return [
            InferenceSuggestionStatus::Pending->value => 'Pending',
            InferenceSuggestionStatus::Accepted->value => 'Accepted',
            InferenceSuggestionStatus::Rejected->value => 'Rejected',
            InferenceSuggestionStatus::Superseded->value => 'Superseded',
        ];
    }

    /** @return array<string, string> */
    protected static function suggestionTypeOptions(): array
    {
        return [
            InferenceSuggestion::TYPE_VIRTUAL_SYSTEM_MEMBERSHIP => 'Virtual system membership',
            InferenceSuggestion::TYPE_VIRTUAL_CONTAINER_MEMBERSHIP => 'Virtual container membership',
            InferenceSuggestion::TYPE_REPOSITORY_MAPPING => 'Repository mapping',
            InferenceSuggestion::TYPE_TRACKER_PROJECT_MAPPING => 'Tracker project mapping',
        ];
    }

    protected static function suggestionTypeLabel(string $state): string
    {
        return static::suggestionTypeOptions()[$state] ?? $state;
    }

    protected static function evidenceReason(InferenceSuggestion $record): string
    {
        $evidenceRaw = $record->getAttribute('evidence');
        $evidence = is_array($evidenceRaw) ? $evidenceRaw : [];

        $reason = $evidence['reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? $reason : '-';
    }

    protected static function sourceValues(InferenceSuggestion $record): string
    {
        $evidenceRaw = $record->getAttribute('evidence');
        $evidence = is_array($evidenceRaw) ? $evidenceRaw : [];

        $key = $evidence['matched_key'] ?? null;
        $value = $evidence['matched_value'] ?? null;

        if (! is_string($key) || ! is_string($value) || trim($value) === '') {
            return '-';
        }

        return $key . ': ' . $value;
    }

    protected static function modelLabel(?Model $model, ?string $fallbackType, ?int $fallbackId): string
    {
        if ($model instanceof Model) {
            $name = $model->getAttribute('name');

            if (is_string($name) && trim($name) !== '') {
                return $name;
            }

            return class_basename($model::class) . ' #' . $model->getKey();
        }

        if (! is_string($fallbackType) || $fallbackType === '' || ! is_int($fallbackId)) {
            return '-';
        }

        return class_basename($fallbackType) . ' #' . $fallbackId;
    }

    /** @return array<int, Select|TextInput> */
    protected static function editBeforeAcceptForm(InferenceSuggestion $record): array
    {
        return match ($record->proposed_action) {
            InferenceSuggestion::ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM,
            InferenceSuggestion::ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER => [
                TextInput::make('target_link_id')
                    ->label('Virtual link ID')
                    ->numeric()
                    ->required(),
            ],
            InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING => [
                Select::make('repository_provider_id')
                    ->label('Repository provider')
                    ->options(fn (): array => RepositoryProvider::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable(),
                TextInput::make('repository_name')
                    ->label('Repository name')
                    ->required(),
                TextInput::make('default_branch')
                    ->label('Default branch')
                    ->default('main'),
                TextInput::make('path_prefix')
                    ->label('Path prefix')
                    ->nullable(),
            ],
            InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK => [
                Select::make('tracker_id')
                    ->label('Tracker')
                    ->options([
                        'jira' => 'Jira',
                        'github' => 'GitHub',
                    ])
                    ->required(),
                TextInput::make('project_key')
                    ->label('Project key')
                    ->required(),
                TextInput::make('project_name')
                    ->label('Project name')
                    ->nullable(),
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    protected static function editBeforeAcceptDefaults(InferenceSuggestion $record): array
    {
        $evidenceRaw = $record->getAttribute('evidence');
        $evidence = is_array($evidenceRaw) ? $evidenceRaw : [];

        return match ($record->proposed_action) {
            InferenceSuggestion::ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM,
            InferenceSuggestion::ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER => [
                'target_link_id' => $record->target_id,
            ],
            InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING => [
                'repository_provider_id' => $evidence['repository_provider_id'] ?? $record->target_id,
                'repository_name' => $evidence['repository_name'] ?? null,
                'default_branch' => $evidence['default_branch'] ?? 'main',
                'path_prefix' => $evidence['path_prefix'] ?? null,
            ],
            InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK => [
                'tracker_id' => $evidence['tracker_id'] ?? null,
                'project_key' => $evidence['matched_value'] ?? null,
                'project_name' => $evidence['project_name'] ?? null,
            ],
            default => [],
        };
    }

    protected static function reviewService(): InferenceSuggestionReviewService
    {
        return app(InferenceSuggestionReviewService::class);
    }

    protected static function reviewer(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'reviewer' => 'Authenticated reviewer is required.',
            ]);
        }

        return $user;
    }

    protected static function canReview(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(['Plan', 'Admin']);
    }

    protected static function isPending(InferenceSuggestion $record): bool
    {
        $status = InferenceSuggestionStatus::from((string) $record->getRawOriginal('status'));

        return $status === InferenceSuggestionStatus::Pending;
    }
}
