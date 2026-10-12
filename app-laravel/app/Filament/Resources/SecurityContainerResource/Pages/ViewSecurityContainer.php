<?php

namespace App\Filament\Resources\SecurityContainerResource\Pages;

use App\Assets\ContainerLinkChange;
use App\Assets\ContainerSystemLinkService;
use App\Filament\Resources\SecurityContainerResource;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class ViewSecurityContainer extends ViewRecord
{
    protected static string $resource = SecurityContainerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('moveToSystem')
                ->label('Move to another system')
                ->icon('heroicon-o-arrows-right-left')
                ->visible(fn (): bool => Gate::allows('context.curate'))
                ->requiresConfirmation()
                ->modalDescription('The container\'s alerts, local findings and SBOM components move with it, and later fetches keep the new link until it is restored.')
                ->form([
                    Select::make('software_system_id')
                        ->label('Software system')
                        ->options(fn (): array => $this->targetOptions())
                        ->searchable()
                        ->required(),
                ])
                ->action(fn (array $data) => $this->moveToSystem((int) $data['software_system_id'])),

            Action::make('restoreSourceLink')
                ->label('Restore source link')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->container()->isSystemOverridden() && Gate::allows('context.curate'))
                ->requiresConfirmation()
                ->modalDescription('Link the container back to the system its source reports it under; its alerts, local findings and SBOM components move with it.')
                ->action(fn () => $this->restoreSourceLink()),
        ];
    }

    public function moveToSystem(int $softwareSystemId): void
    {
        Gate::authorize('context.curate');

        $target = SoftwareSystem::query()->findOrFail($softwareSystemId);

        $this->applyLinkChange(
            fn (ContainerSystemLinkService $service, ?User $author) => $service->relink($this->container(), $target, $author),
            "Moved to {$target->name}",
        );
    }

    public function restoreSourceLink(): void
    {
        Gate::authorize('context.curate');

        $this->applyLinkChange(
            fn (ContainerSystemLinkService $service, ?User $author) => $service->reset($this->container(), $author),
            'Source link restored',
        );
    }

    /** @param  callable(ContainerSystemLinkService, ?User): ContainerLinkChange  $change */
    private function applyLinkChange(callable $change, string $successTitle): void
    {
        $author = Auth::user();

        try {
            $this->record = $change(app(ContainerSystemLinkService::class), $author instanceof User ? $author : null)->container;
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title('Link not changed')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    /** @return array<int, string> */
    private function targetOptions(): array
    {
        return SoftwareSystem::query()
            ->whereNull('removed_at')
            ->whereKeyNot($this->container()->software_system_id)
            ->orderBy('name')
            ->get(['id', 'name', 'source_id'])
            ->mapWithKeys(fn (SoftwareSystem $system): array => [$system->id => "{$system->name} ({$system->source_id})"])
            ->all();
    }

    private function container(): SecurityContainer
    {
        $record = $this->getRecord();

        if (! $record instanceof SecurityContainer) {
            throw new \LogicException('ViewSecurityContainer expects a SecurityContainer record.');
        }

        return $record;
    }
}
