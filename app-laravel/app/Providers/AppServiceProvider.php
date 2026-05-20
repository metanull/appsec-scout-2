<?php

namespace App\Providers;

use App\Events\SyncRunFinished;
use App\Listeners\BustDashboardCache;
use App\Sources\Asoc\AsocSource;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Detectify\DetectifySource;
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

        $this->app->alias(AzDoSource::class, 'appsec-scout.source.azdo');
        $this->app->alias(AsocSource::class, 'appsec-scout.source.asoc');
        $this->app->alias(DetectifySource::class, 'appsec-scout.source.detectify');

        $this->app->tag([
            AzDoSource::class,
            AsocSource::class,
            DetectifySource::class,
        ], 'appsec-scout.source');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SyncRunFinished::class, BustDashboardCache::class);
    }
}
