<?php

namespace App\Http\Controllers;

use App\Filament\Resources\SecurityEventResource;
use App\Models\SecurityEvent;
use App\Models\User;
use App\SourceCode\AlertSourceLocator;
use Filament\Notifications\Notification;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Opens an alert's source file (current code or the alert's commit) at its provider. When it
 * cannot — no Azure DevOps Repos credential, the file is not in the repository, Azure DevOps
 * refused the request — it returns to the alert with the reason as a notification.
 */
class AlertSourceRedirectController
{
    public function __invoke(SecurityEvent $event, string $version, AlertSourceLocator $locator): RedirectResponse
    {
        Gate::authorize('alerts.view');

        abort_if($locator->appUrl($event, $version) === null, 404);

        /** @var User $user */
        $user = auth()->user();

        try {
            return redirect()->away($locator->url($event, $version, $user));
        } catch (InvalidArgumentException $e) {
            return $this->backToAlert($event, $e->getMessage());
        } catch (GuzzleException $e) {
            report($e);

            return $this->backToAlert($event, 'Azure DevOps did not answer the request for this file. Check your Azure DevOps Repos credential under Profile → Integrations.');
        }
    }

    private function backToAlert(SecurityEvent $event, string $reason): RedirectResponse
    {
        Notification::make()
            ->title('Could not open the source file')
            ->body($reason)
            ->danger()
            ->persistent()
            ->send();

        return redirect(SecurityEventResource::getUrl('view', ['record' => $event]));
    }
}
