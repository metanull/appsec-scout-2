<?php

namespace App\Providers;

use App\Credentials\Vault;
use App\Events\SyncRunFinished;
use App\Listeners\BustDashboardCache;
use App\Models\User;
use App\SourceControl\AzDo\AzDoRepos;
use App\SourceControl\GitHub\GitHubRepos;
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
        $this->app->singleton(AzDoRepos::class);
        $this->app->singleton(GitHubRepos::class);

        $this->app->alias(AzDoSource::class, 'appsec-scout.source.azdo');
        $this->app->alias(AsocSource::class, 'appsec-scout.source.asoc');
        $this->app->alias(DetectifySource::class, 'appsec-scout.source.detectify');
        $this->app->alias(GitHubTracker::class, 'appsec-scout.tracker.github');
        $this->app->alias(JiraTracker::class, 'appsec-scout.tracker.jira');
        $this->app->alias(AzDoRepos::class, 'appsec-scout.source-control.azdo-repos');
        $this->app->alias(GitHubRepos::class, 'appsec-scout.source-control.github-repos');

        $this->app->tag([
            AzDoSource::class,
            AsocSource::class,
            DetectifySource::class,
        ], 'appsec-scout.source');

        $this->app->tag([
            GitHubTracker::class,
            JiraTracker::class,
        ], 'appsec-scout.tracker');

        $this->app->tag([
            AzDoRepos::class,
            GitHubRepos::class,
        ], 'appsec-scout.source-control');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SyncRunFinished::class, BustDashboardCache::class);
        // ParseAttachmentIntoFindings/PushSbomAttachmentToDependencyTrack are deliberately
        // NOT registered here — Laravel auto-discovers app/Listeners classes by their typed
        // handle() parameter, and registering them again here would double-fire both.
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->save();
            }
        });
    }
}
