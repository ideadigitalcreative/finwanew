<?php

namespace App\Modules\RisenAI\Providers;

use App\Modules\RisenAI\Console\GenerateDataLakeCommand;
use App\Modules\RisenAI\Console\GeneratePagesCommand;
use App\Modules\RisenAI\Console\GenerateSemanticGraphCommand;
use App\Modules\RisenAI\Console\RunPerformanceAuditCommand;
use App\Modules\RisenAI\Services\DataLakeService;
use App\Modules\RisenAI\Services\IntentMatcherService;
use App\Modules\RisenAI\Services\OpenRouterService;
use App\Modules\RisenAI\Services\PageGeneratorService;
use App\Modules\RisenAI\Services\PerformanceMonitorService;
use App\Modules\RisenAI\Services\RagContentService;
use App\Modules\RisenAI\Services\SemanticGraphService;
use Illuminate\Support\ServiceProvider;

class RisenAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind semua services
        $this->app->singleton(OpenRouterService::class);
        $this->app->singleton(DataLakeService::class);
        $this->app->singleton(SemanticGraphService::class);
        $this->app->singleton(PageGeneratorService::class);
        $this->app->singleton(RagContentService::class);
        $this->app->singleton(IntentMatcherService::class);
        $this->app->singleton(PerformanceMonitorService::class);

        // Load config
        $this->mergeConfigFrom(
            __DIR__.'/../config/risen-ai.php', 'risen-ai'
        );
    }

    public function boot(): void
    {
        // Load routes baru — tidak timpa routes Finwa
        if (file_exists(base_path('routes/risen-ai.php'))) {
            $this->loadRoutesFrom(base_path('routes/risen-ai.php'));
        }

        // Load migrations baru
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'risen-ai');

        // Register artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateDataLakeCommand::class,
                GenerateSemanticGraphCommand::class,
                GeneratePagesCommand::class,
                RunPerformanceAuditCommand::class,
            ]);
        }
    }
}
