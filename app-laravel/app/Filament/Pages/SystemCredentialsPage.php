<?php

namespace App\Filament\Pages;

use App\Assets\DependencyTrack\DependencyTrackSystemCredentials;
use App\Credentials\CredentialField;
use App\Filament\Pages\Concerns\ManagesIntegrationCredentials;
use App\Models\User;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class SystemCredentialsPage extends Page implements HasForms
{
    use ManagesIntegrationCredentials;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 21;

    protected static ?string $navigationLabel = 'System Credentials';

    protected static ?string $slug = 'admin/system-credentials';

    public function mount(): void
    {
        $this->mountManagesIntegrationCredentials();
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.system-pats') : false;
    }

    public function heading(): string
    {
        return 'System credentials';
    }

    public function subheading(): string
    {
        return 'Manage the credentials used by scheduled jobs and background tracker or source synchronization.';
    }

    protected function credentialOwnerId(): ?int
    {
        return null;
    }

    /**
     * Dependency-Track credentials are system-scope only, so the section exists
     * here and not on Profile -> Integrations.
     *
     * @return list<array{id: string, type: string, display_name: string, instance: DependencyTrackSystemCredentials, credential_fields: list<CredentialField>}>
     */
    protected function extraIntegrationEntries(): array
    {
        $dependencyTrack = app(DependencyTrackSystemCredentials::class);

        return [[
            'id' => $dependencyTrack->id(),
            'type' => 'platform',
            'display_name' => $dependencyTrack->displayName(),
            'instance' => $dependencyTrack,
            'credential_fields' => $dependencyTrack->credentialFields(),
        ]];
    }
}
