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
use App\Services\Integrations\Providers\GitHubIntegrationProvider;
use App\Services\Integrations\Providers\GoogleIntegrationProvider;
use App\Services\Integrations\Providers\TelegramIntegrationProvider;
use App\Services\Tools\CancelToolActionTool;
use App\Services\Tools\ConfirmToolActionTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\GitHub\CommentGitHubIssueTool;
use App\Services\Tools\GitHub\CompareGitHubRefsTool;
use App\Services\Tools\GitHub\CreateGitHubBranchTool;
use App\Services\Tools\GitHub\CreateGitHubIssueTool;
use App\Services\Tools\GitHub\CreateGitHubPullRequestTool;
use App\Services\Tools\GitHub\GetGitHubCommitTool;
use App\Services\Tools\GitHub\GetGitHubFileTool;
use App\Services\Tools\GitHub\GetGitHubIssueTool;
use App\Services\Tools\GitHub\GetGitHubPullRequestDiffTool;
use App\Services\Tools\GitHub\GetGitHubPullRequestTool;
use App\Services\Tools\GitHub\GetGitHubRepositoryTool;
use App\Services\Tools\GitHub\GetGitHubWorkflowRunTool;
use App\Services\Tools\GitHub\ListGitHubBranchesTool;
use App\Services\Tools\GitHub\ListGitHubCommitsTool;
use App\Services\Tools\GitHub\ListGitHubIssuesTool;
use App\Services\Tools\GitHub\ListGitHubPullRequestsTool;
use App\Services\Tools\GitHub\ListGitHubRepositoriesTool;
use App\Services\Tools\GitHub\ListGitHubWorkflowRunsTool;
use App\Services\Tools\GitHub\SearchGitHubCodeTool;
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
use App\Services\Tools\Storage\DeleteStorageFileTool;
use App\Services\Tools\Storage\GetStorageFileTool;
use App\Services\Tools\Storage\ListStorageFilesTool;
use App\Services\Tools\Storage\ReadStorageFileChunksTool;
use App\Services\Tools\Storage\SearchStorageFileContentsTool;
use App\Services\Tools\Storage\SearchStorageFilesTool;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\WebResearch\FetchWebPageTool;
use App\Services\Tools\WebResearch\SearchWebTool;
use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\SpeechToTextManager;
use App\Services\Voice\TextToSpeechManager;
use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\WebSearchManager;
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

        $this->app->bind(WebSearchProvider::class, function ($app): WebSearchProvider {
            return $app->make(WebSearchManager::class)->activeProvider();
        });

        $this->app->bind(SpeechToTextProvider::class, function ($app): SpeechToTextProvider {
            return $app->make(SpeechToTextManager::class)->activeProvider();
        });

        $this->app->bind(TextToSpeechProvider::class, function ($app): TextToSpeechProvider {
            return $app->make(TextToSpeechManager::class)->activeProvider();
        });

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
                $app->make(ListGitHubRepositoriesTool::class),
                $app->make(GetGitHubRepositoryTool::class),
                $app->make(ListGitHubBranchesTool::class),
                $app->make(ListGitHubCommitsTool::class),
                $app->make(GetGitHubCommitTool::class),
                $app->make(CompareGitHubRefsTool::class),
                $app->make(GetGitHubFileTool::class),
                $app->make(SearchGitHubCodeTool::class),
                $app->make(ListGitHubIssuesTool::class),
                $app->make(GetGitHubIssueTool::class),
                $app->make(ListGitHubPullRequestsTool::class),
                $app->make(GetGitHubPullRequestTool::class),
                $app->make(GetGitHubPullRequestDiffTool::class),
                $app->make(ListGitHubWorkflowRunsTool::class),
                $app->make(GetGitHubWorkflowRunTool::class),
                $app->make(CreateGitHubIssueTool::class),
                $app->make(CommentGitHubIssueTool::class),
                $app->make(CreateGitHubBranchTool::class),
                $app->make(CreateGitHubPullRequestTool::class),
                $app->make(ListStorageFilesTool::class),
                $app->make(SearchStorageFilesTool::class),
                $app->make(GetStorageFileTool::class),
                $app->make(SearchStorageFileContentsTool::class),
                $app->make(ReadStorageFileChunksTool::class),
                $app->make(DeleteStorageFileTool::class),
                $app->make(SearchWebTool::class),
                $app->make(FetchWebPageTool::class),
                $app->make(ConfirmToolActionTool::class),
                $app->make(CancelToolActionTool::class),
            ]);
        });

        $this->app->singleton(IntegrationRegistry::class, function ($app): IntegrationRegistry {
            return new IntegrationRegistry([
                $app->make(GoogleIntegrationProvider::class),
                $app->make(TelegramIntegrationProvider::class),
                $app->make(ElevenLabsIntegrationProvider::class),
                $app->make(GitHubIntegrationProvider::class),
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
