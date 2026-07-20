<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Http\Middleware\EnsureUserIsEnabled;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppSecScoutPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('appsec-scout')
            ->path('')
            ->login()
            ->colors(['primary' => Color::Amber])
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->navigationGroups([
                NavigationGroup::make('Reader'),
                NavigationGroup::make('Admin'),
                NavigationGroup::make('Sync'),
            ])
            ->navigationItems([
                NavigationItem::make('Profile')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (): ?string => Filament::getProfileUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.appsec-scout.auth.profile'))
                    ->sort(-1),
                NavigationItem::make('Profile integrations')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => ProfileIntegrationsPage::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs(ProfileIntegrationsPage::getRouteName()))
                    ->sort(0),
            ])
            ->profile(isSimple: false)
            ->userMenuItems([
                'profile-integrations' => MenuItem::make()
                    ->label('Profile integrations')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => ProfileIntegrationsPage::getUrl()),
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->middleware($this->webMiddleware())
            ->authMiddleware([EnsureUserIsEnabled::class, Authenticate::class]);
    }

    /** @return list<class-string> */
    private function webMiddleware(): array
    {
        return [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ];
    }
}
