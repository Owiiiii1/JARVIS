<?php

namespace App\Providers;

use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\ProviderAiChatGateway;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiChatGateway::class, ProviderAiChatGateway::class);

        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            return new ToolRegistry([
                $app->make(CreateReminderTool::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
