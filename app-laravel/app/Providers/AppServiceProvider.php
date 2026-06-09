<?php

namespace App\Providers;

use App\Credentials\Vault;
use App\Events\SyncRunFinished;
use App\Listeners\BustDashboardCache;
use App\Listeners\GenerateInferenceSuggestions;
use App\Models\User;
use App\Sources\Asoc\AsocSource;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Detectify\DetectifySource;
use App\Trackers\GitHub\GitHubTracker;
use App\Trackers\Jira\JiraTracker;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Vault::class);
        $this->app->singleton(AzDoSource::class);
        $this->app->singleton(AsocSource::class);
        $this->app->singleton(DetectifySource::class);
        $this->app->singleton(GitHubTracker::class);
        $this->app->singleton(JiraTracker::class);

        $this->app->alias(AzDoSource::class, 'appsec-scout.source.azdo');
        $this->app->alias(AsocSource::class, 'appsec-scout.source.asoc');
        $this->app->alias(DetectifySource::class, 'appsec-scout.source.detectify');
        $this->app->alias(GitHubTracker::class, 'appsec-scout.tracker.github');
        $this->app->alias(JiraTracker::class, 'appsec-scout.tracker.jira');

        $this->app->tag([
            AzDoSource::class,
            AsocSource::class,
            DetectifySource::class,
        ], 'appsec-scout.source');

        $this->app->tag([
            GitHubTracker::class,
            JiraTracker::class,
        ], 'appsec-scout.tracker');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SyncRunFinished::class, BustDashboardCache::class);
        Event::listen(SyncRunFinished::class, GenerateInferenceSuggestions::class);
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->save();
            }
        });
    }
}
