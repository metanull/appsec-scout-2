<?php

namespace App\Filament\Resources\SecurityEventResource\RelationManagers;

use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Triage\CommentManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CommentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'comments';

    protected static ?string $title = 'Comments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')
                ->label('Comment')
                ->required()
                ->minLength(10)
                ->rows(4),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author.name')
                    ->label('Author')
                    ->placeholder('System')
                    ->sortable(),
                TextColumn::make('upstream_comment_id')
                    ->label('Origin')
                    ->state(fn (EventComment $record): string => $record->upstream_comment_id ? 'From source' : 'Local')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'From source' ? 'gray' : 'info'),
                TextColumn::make('body')
                    ->label('Comment')
                    ->wrap()
                    ->grow(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25, 50])
            ->headerActions([
                Action::make('addComment')
                    ->label('Add comment')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn (): bool => auth()->user()?->can('alerts.edit') ?? false)
                    ->form([
                        Textarea::make('body')
                            ->label('Comment')
                            ->required()
                            ->minLength(10)
                            ->rows(4),
                    ])
                    ->action(function (array $data): void {
                        $user = Auth::user();

                        if ($user === null || ! $user->can('alerts.edit')) {
                            abort(403);
                        }

                        $ownerRecord = $this->getOwnerRecord();

                        if (! $ownerRecord instanceof SecurityEvent) {
                            abort(500);
                        }

                        try {
                            app(CommentManager::class)->add(
                                $ownerRecord,
                                $user,
                                (string) $data['body'],
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($exception->errors()['comment'][0] ?? 'Unable to add comment.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Comment added')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('editComment')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->visible(fn (EventComment $record): bool => $this->canEditComment($record))
                    ->form(fn (EventComment $record): array => [
                        Textarea::make('body')
                            ->label('Comment')
                            ->default($record->body)
                            ->required()
                            ->minLength(10)
                            ->rows(4),
                    ])
                    ->action(function (EventComment $record, array $data): void {
                        $user = Auth::user();

                        if ($user === null) {
                            abort(403);
                        }

                        if (! $this->canEditComment($record)) {
                            abort(403);
                        }

                        try {
                            app(CommentManager::class)->update($record, $user, (string) $data['body']);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title($exception->errors()['comment'][0] ?? 'Unable to save comment.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Comment updated')->success()->send();
                    }),
            ]);
    }

    private function canEditComment(EventComment $comment): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(CommentManager::class)->canEdit($comment, $user, now());
    }
}
