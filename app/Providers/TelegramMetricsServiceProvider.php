<?php

namespace App\Providers;

use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Records\Gauge;

class TelegramMetricsServiceProvider extends ServiceProvider
{
    /**
     * Register metrics recorder.
     */
    public function register(): void
    {
        // Register recorder for Pulse
        $this->app->singleton(\App\Records\TelegramMessages::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Pulse metrics
        if (class_exists(Pulse::class)) {
            $this->registerPulseMetrics();
        }
    }

    protected function registerPulseMetrics(): void
    {
        // Register recorder
        Pulse::record(\App\Records\TelegramMessages::class)->make();

        // Register dashboard card (optional - add to Pulse dashboard manually)
        // See: https://laravel.com/docs/pulse#dashboard-cards
    }
}
