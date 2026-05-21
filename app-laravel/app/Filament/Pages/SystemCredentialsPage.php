<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesIntegrationCredentials;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class SystemCredentialsPage extends Page
{
    use ManagesIntegrationCredentials;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'System credentials';

    protected static ?string $slug = 'admin/system-credentials';

    protected string $view = 'filament.pages.integration-credentials-page';

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
}
