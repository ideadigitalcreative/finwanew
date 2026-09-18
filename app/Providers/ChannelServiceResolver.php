<?php

namespace App\Providers;

use App\ChannelServiceInterface;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ChannelServiceResolver extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ChannelServiceInterface::class, function ($app) {
            return new ChannelServiceResolverService($app);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
