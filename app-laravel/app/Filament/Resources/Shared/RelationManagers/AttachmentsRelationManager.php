<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Assets\AttachmentService;
use App\Models\Attachment;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;

class AttachmentsRelationManager extends RelationManager
{
    use WithFileUploads;

    protected static bool $isLazy = false;

    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Attachments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->grow(),
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('mime')
                    ->label('Type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => self::formatBytes($state)),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->state(fn (Attachment $record): string => ($record->createdBy !== null ? $record->createdBy->name : null) ?? $record->created_by_command ?? '—')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->headerActions([
                Action::make('upload')
                    ->label('Add attachment')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => $this->canMutate())
                    ->form([
                        TextInput::make('kind')
                            ->label('Kind')
                            ->required()
                            ->maxLength(64)
                            ->helperText('e.g. sbom, dependency-report, http-headers, pipeline-run'),
                        FileUpload::make('file')
                            ->label('File')
                            ->disk('local')
                            ->directory('attachments-upload')
                            ->required(),
                        TextInput::make('name')
                            ->label('Name')
                            ->maxLength(255)
                            ->helperText('Defaults to the uploaded file name.'),
                    ])
                    ->action(fn (array $data): bool => $this->upload($data)),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('download')
                        ->label('Download')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (Attachment $record): string => route($this->downloadRouteName(), [
                            $this->downloadRouteParam() => $this->getOwnerRecord()->getKey(),
                            'attachment' => $record->id,
                        ]))
                        ->openUrlInNewTab(),
                    Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => $this->canMutate())
                        ->requiresConfirmation()
                        ->modalHeading('Delete attachment')
                        ->modalDescription('Permanently delete this attachment?')
                        ->action(function (Attachment $record): void {
                            app(AttachmentService::class)->delete($record);

                            Notification::make()->title('Attachment deleted')->success()->send();
                        }),
                ]),
            ])
            ->emptyStateDescription('No attachments yet.');
    }

    /** @param array<string, mixed> $data */
    private function upload(array $data): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $relativePath = (string) $data['file'];
        $fullPath = storage_path('app/' . $relativePath);

        if (! file_exists($fullPath)) {
            Notification::make()->title('Uploaded file not found')->danger()->send();

            return false;
        }

        $fileContent = file_get_contents($fullPath);

        if ($fileContent === false) {
            Notification::make()->title('Could not read uploaded file')->danger()->send();

            return false;
        }

        $name = is_string($data['name'] ?? null) && trim((string) $data['name']) !== ''
            ? trim((string) $data['name'])
            : basename($relativePath);

        $mime = mime_content_type($fullPath) ?: 'application/octet-stream';

        app(AttachmentService::class)->attachTo(
            owner: $this->attachableOwner(),
            kind: (string) $data['kind'],
            mime: $mime,
            name: $name,
            payload: $fileContent,
            createdByUserId: $user->id,
        );

        @unlink($fullPath);

        Notification::make()->title('Attachment added')->success()->send();

        return true;
    }

    private function canMutate(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('context.curate');
    }

    private function attachableOwner(): SoftwareAsset|SoftwareSystem|SecurityContainer
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof SoftwareAsset || $owner instanceof SoftwareSystem || $owner instanceof SecurityContainer) {
            return $owner;
        }

        abort(500);
    }

    private function downloadRouteName(): string
    {
        $owner = $this->attachableOwner();

        return match (true) {
            $owner instanceof SoftwareAsset => 'assets.attachments.download',
            $owner instanceof SoftwareSystem => 'software-systems.attachments.download',
            $owner instanceof SecurityContainer => 'security-containers.attachments.download',
        };
    }

    private function downloadRouteParam(): string
    {
        $owner = $this->attachableOwner();

        return match (true) {
            $owner instanceof SoftwareAsset => 'asset',
            $owner instanceof SoftwareSystem => 'system',
            $owner instanceof SecurityContainer => 'container',
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }
}
