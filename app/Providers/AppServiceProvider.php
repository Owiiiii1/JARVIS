<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\TelegramGroup;
use App\Policies\ProjectPolicy;
use App\Policies\TelegramGroupPolicy;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\ProviderAiChatGateway;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\Providers\ElevenLabsIntegrationProvider;
use App\Services\Integrations\Providers\GoogleIntegrationProvider;
use App\Services\Integrations\Providers\TelegramIntegrationProvider;
use App\Services\Tools\CancelToolActionTool;
use App\Services\Tools\ConfirmToolActionTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\Google\CreateCalendarEventTool;
use App\Services\Tools\Google\CreateGmailDraftTool;
use App\Services\Tools\Google\DeleteCalendarEventTool;
use App\Services\Tools\Google\GetCalendarEventTool;
use App\Services\Tools\Google\GetGmailMessageTool;
use App\Services\Tools\Google\GetGmailThreadTool;
use App\Services\Tools\Google\GoogleCalendarFreebusyTool;
use App\Services\Tools\Google\ListCalendarEventsTool;
use App\Services\Tools\Google\ListGmailLabelsTool;
use App\Services\Tools\Google\ListGmailMessagesTool;
use App\Services\Tools\Google\ListGoogleCalendarsTool;
use App\Services\Tools\Google\ModifyGmailLabelsTool;
use App\Services\Tools\Google\SearchCalendarEventsTool;
use App\Services\Tools\Google\SearchGmailTool;
use App\Services\Tools\Google\SendGmailMessageTool;
use App\Services\Tools\Google\UpdateCalendarEventTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\SearchGroupKnowledgeTool;
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
                $app->make(SearchGroupKnowledgeTool::class),
                $app->make(ListGoogleCalendarsTool::class),
                $app->make(ListCalendarEventsTool::class),
                $app->make(GetCalendarEventTool::class),
                $app->make(SearchCalendarEventsTool::class),
                $app->make(GoogleCalendarFreebusyTool::class),
                $app->make(CreateCalendarEventTool::class),
                $app->make(UpdateCalendarEventTool::class),
                $app->make(DeleteCalendarEventTool::class),
                $app->make(SearchGmailTool::class),
                $app->make(ListGmailMessagesTool::class),
                $app->make(GetGmailMessageTool::class),
                $app->make(GetGmailThreadTool::class),
                $app->make(ListGmailLabelsTool::class),
                $app->make(CreateGmailDraftTool::class),
                $app->make(SendGmailMessageTool::class),
                $app->make(ModifyGmailLabelsTool::class),
                $app->make(ConfirmToolActionTool::class),
                $app->make(CancelToolActionTool::class),
            ]);
        });

        $this->app->singleton(IntegrationRegistry::class, function ($app): IntegrationRegistry {
            return new IntegrationRegistry([
                $app->make(GoogleIntegrationProvider::class),
                $app->make(TelegramIntegrationProvider::class),
                $app->make(ElevenLabsIntegrationProvider::class),
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
