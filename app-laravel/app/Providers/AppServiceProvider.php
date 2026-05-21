<?php

namespace App\Providers;

use App\Events\SyncRunFinished;
use App\Listeners\BustDashboardCache;
use App\Sources\Asoc\AsocSource;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Detectify\DetectifySource;
use App\Trackers\GitHub\GitHubTracker;
use App\Trackers\Jira\JiraTracker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
    }
}
