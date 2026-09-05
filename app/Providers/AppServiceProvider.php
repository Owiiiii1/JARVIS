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
use App\Services\Telegram\Contracts\CompletesTelegramUserTurn;
use App\Services\Telegram\Contracts\LooksUpTelegramInbound;
use App\Services\Telegram\SpokenTextNormalizer;
use App\Services\Telegram\TelegramBotManager;
use App\Services\Telegram\TelegramChatKeyboard;
use App\Services\Telegram\TelegramConversationTurnBridge;
use App\Services\Telegram\TelegramInboundLookup;
use App\Services\Telegram\TelegramReplyDeliveryService;
use App\Services\Telegram\TelegramVoiceInboundService;
use App\Services\Telegram\TelegramVoiceSuitabilityPolicy;
use App\Services\Tools\CancelToolActionTool;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\ConfirmToolActionTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetAssistantProfileTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\GetTelegramResponseModeTool;
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
use App\Services\Tools\SetTelegramResponseModeTool;
use App\Services\Tools\Storage\DeleteStorageFileTool;
use App\Services\Tools\Storage\GetStorageFileTool;
use App\Services\Tools\Storage\ListStorageFilesTool;
use App\Services\Tools\Storage\ReadStorageFileChunksTool;
use App\Services\Tools\Storage\SearchStorageFileContentsTool;
use App\Services\Tools\Storage\SearchStorageFilesTool;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\UpdateAssistantProfileTool;
use App\Services\Tools\WebResearch\FetchWebPageTool;
use App\Services\Tools\WebResearch\SearchWebTool;
use App\Services\Users\ResolvesTelegramResponseMode;
use App\Services\Users\UserChannelPreferenceService;
use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use App\Services\Voice\Contracts\ResolvesUserVoice;
use App\Services\Voice\Contracts\SpeechSynthesizer;
use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\Contracts\TranscribesSpeech;
use App\Services\Voice\SpeechToTextManager;
use App\Services\Voice\TextToSpeechManager;
use App\Services\Voice\VoiceMetricsLogger;
use App\Services\Voice\VoiceSettingsService;
use App\Services\Voice\VoiceTempAudioStore;
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
        $this->app->bind(ResolvesTelegramResponseMode::class, UserChannelPreferenceService::class);
        $this->app->bind(ResolvesUserVoice::class, VoiceSettingsService::class);
        $this->app->bind(SpeechSynthesizer::class, TextToSpeechManager::class);
        $this->app->bind(StoresEphemeralVoiceAudio::class, VoiceTempAudioStore::class);
        $this->app->bind(RecordsVoiceMetrics::class, VoiceMetricsLogger::class);
        $this->app->bind(TranscribesSpeech::class, SpeechToTextManager::class);
        $this->app->bind(LooksUpTelegramInbound::class, TelegramInboundLookup::class);
        $this->app->bind(CompletesTelegramUserTurn::class, TelegramConversationTurnBridge::class);

        $this->app->singleton(TelegramVoiceInboundService::class, function ($app): TelegramVoiceInboundService {
            return new TelegramVoiceInboundService(
                $app->make(LooksUpTelegramInbound::class),
                $app->make(TranscribesSpeech::class),
                $app->make(StoresEphemeralVoiceAudio::class),
                $app->make(CompletesTelegramUserTurn::class),
                $app->make(RecordsVoiceMetrics::class),
                max(1024, (int) config('voice.telegram_voice.max_inbound_bytes', 2_000_000)),
                max(1, (int) config('voice.telegram_voice.max_inbound_seconds', 30)),
                max(1024, (int) config('voice.telegram_voice.api_download_max_bytes', 20_000_000)),
            );
        });

        $this->app->singleton(TelegramReplyDeliveryService::class, function ($app): TelegramReplyDeliveryService {
            return new TelegramReplyDeliveryService(
                $app->make(ResolvesTelegramResponseMode::class),
                $app->make(ResolvesUserVoice::class),
                $app->make(SpeechSynthesizer::class),
                $app->make(StoresEphemeralVoiceAudio::class),
                $app->make(TelegramVoiceSuitabilityPolicy::class),
                $app->make(TelegramChatKeyboard::class),
                $app->make(RecordsVoiceMetrics::class),
                $app->make(TelegramBotManager::class),
                max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000)),
            );
        });

        $this->app->singleton(TelegramVoiceSuitabilityPolicy::class, function ($app): TelegramVoiceSuitabilityPolicy {
            return new TelegramVoiceSuitabilityPolicy(
                $app->make(SpokenTextNormalizer::class),
                max(200, (int) config('voice.telegram_voice.max_spoken_chars', 2000)),
                max(50, (int) config('voice.telegram_voice.max_code_fence_chars', 400)),
                max(2, (int) config('voice.telegram_voice.max_table_rows', 4)),
            );
        });

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
                $app->make(GetAssistantProfileTool::class),
                $app->make(UpdateAssistantProfileTool::class),
                $app->make(CompleteAssistantOnboardingTool::class),
                $app->make(GetTelegramResponseModeTool::class),
                $app->make(SetTelegramResponseModeTool::class),
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
