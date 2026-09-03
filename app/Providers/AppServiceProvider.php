<?php

namespace App\Providers;

use App\Models\Project;
use App\Policies\ProjectPolicy;
use App\Models\TelegramGroup;
use App\Policies\TelegramGroupPolicy;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\ProviderAiChatGateway;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Gate;
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
                $app->make(SearchConversationHistoryTool::class),
                $app->make(GetProjectContextTool::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(TelegramGroup::class, TelegramGroupPolicy::class);
    }
}
