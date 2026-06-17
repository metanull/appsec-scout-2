<?php

namespace App\Filament\Resources\SecurityContainerLinkResource\RelationManagers;

use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityContainerLink;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Alerts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->ownerLink()->eventsQuery())
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (EventSeverity|string $state): string => match ($state instanceof EventSeverity ? $state->value : $state) {
                        EventSeverity::Critical->value => 'danger',
                        EventSeverity::High->value => 'warning',
                        EventSeverity::Medium->value => 'info',
                        EventSeverity::Low->value => 'gray',
                        default => 'secondary',
                    }),
                TextColumn::make('state')
                    ->badge()
                    ->color(fn (EventState|string $state): string => match ($state instanceof EventState ? $state->value : $state) {
                        EventState::Resolved->value => 'success',
                        EventState::Dismissed->value => 'gray',
                        EventState::InProgress->value => 'info',
                        EventState::Acknowledged->value => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('source_id')->label('Source')->badge(),
                TextColumn::make('container.name')->label('Container')->placeholder('-')->wrap(),
                TextColumn::make('title')->searchable()->wrap()->grow(),
                TextColumn::make('updated_at')->label('Updated')->since(),
            ])
            ->recordUrl(fn (SecurityEvent $record): string => SecurityEventResource::getUrl('view', ['record' => $record]))
            ->paginated([10, 25, 50]);
    }

    private function ownerLink(): SecurityContainerLink
    {
        $ownerRecord = $this->getOwnerRecord();

        if (! $ownerRecord instanceof SecurityContainerLink) {
            abort(500);
        }

        return $ownerRecord;
    }
}
