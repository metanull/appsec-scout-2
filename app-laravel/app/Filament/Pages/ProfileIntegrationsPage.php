<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesIntegrationCredentials;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ProfileIntegrationsPage extends Page implements HasForms
{
    use ManagesIntegrationCredentials;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $slug = 'profile/integrations';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.integration-credentials-page';

    public function mount(): void
    {
        $this->mountManagesIntegrationCredentials();
    }

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public function heading(): string
    {
        return 'Profile integrations';
    }

    public function subheading(): string
    {
        return 'Manage the personal credentials used for tracker and source actions you run from the UI.';
    }

    protected function credentialOwnerId(): ?int
    {
        $userId = Auth::id();

        if (is_int($userId)) {
            return $userId;
        }

        if (is_string($userId) && $userId !== '' && ctype_digit($userId)) {
            return (int) $userId;
        }

        return null;
    }
}
