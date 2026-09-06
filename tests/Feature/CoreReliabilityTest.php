<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\AsyncFailureCategory;
use App\Enums\AttachmentRetentionClass;
use App\Enums\AttachmentSummaryStatus;
use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunType;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupAnalysisRunType;
use App\Enums\TelegramGroupStatus;
use App\Jobs\AnalyzeConversationTurnJob;
use App\Jobs\AnalyzeTelegramGroupRangeJob;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SummarizeMessageAttachmentJob;
use App\Jobs\UpdateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\MemoryAnalysisRun;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\ChatAttachments\AttachmentVisionSummaryService;
use App\Services\ChatAttachments\ChatAttachmentConfig;
use App\Services\Conversations\ConversationService;
use App\Services\Groups\GroupAnalysisService;
use App\Services\Memory\ConversationTurnAnalyzer;
use App\Services\Memory\UserProfileService;
use App\Services\Reliability\StaleAsyncRunRecovery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class CoreReliabilityTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_transient_memory_provider_error_is_retryable(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $fake->exception = new AiProviderException('Gemini chat request failed with status 429');
            $user = $this->createTemporaryUser();
            [$job, $run] = $this->turnJob($user);

            try {
                $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));
                $this->fail('Retryable provider error should be rethrown.');
            } catch (AiProviderException $exception) {
                $this->assertSame('Gemini chat request failed with status 429', $exception->getMessage());
            }

            $run->refresh();
            $this->assertSame(MemoryAnalysisRunStatus::Processing, $run->status);
            $this->assertSame([30, 90, 180], $job->backoff());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_permanent_memory_provider_auth_is_terminal(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $fake->exception = new AiProviderException('Gemini chat request failed with status 401');
            $user = $this->createTemporaryUser();
            [$job, $run] = $this->turnJob($user);

            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));

            $run->refresh();
            $this->assertSame(MemoryAnalysisRunStatus::Failed, $run->status);
            $this->assertSame(AsyncFailureCategory::ProviderAuth->value, $run->last_error);
            $this->assertFalse((bool) ($run->metadata['retryable'] ?? true));
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_memory_failed_hook_updates_processing_run(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            [$job, $run] = $this->turnJob($user);
            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Processing,
                'started_at' => now(),
            ])->save();

            $job->failed(new AiSafetyException);

            $run->refresh();
            $this->assertSame(MemoryAnalysisRunStatus::Failed, $run->status);
            $this->assertSame(AsyncFailureCategory::ProviderSafety->value, $run->last_error);
            $this->assertSame('provider_safety', $run->metadata['error_code'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_memory_retry_does_not_duplicate_durable_memories(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Reliability');
            [$from, $to] = $this->addDialogue($conversation, 'Запомни тестовый цвет надёжности', 'Ок');
            $fake->analysisResponseText = '{"topics":[],"memories":[{"kind":"preference","content":"Тестовый цвет надёжности","normalized_key":"reliability test color","confidence":0.9,"action":"create","source_message_ids":['.$from->id.']}]}';
            $job = new AnalyzeConversationTurnJob((int) $user->id, (int) $conversation->id, (int) $from->id, (int) $to->id);
            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));
            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));

            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, MemoryAnalysisRun::query()->where('user_id', $user->id)->where('status', MemoryAnalysisRunStatus::Completed)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_deleted_conversation_is_terminal_missing_source(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $this->bindFake();
            $user = $this->createTemporaryUser();
            [$job] = $this->turnJob($user);
            Message::query()->whereKey([$job->fromMessageId, $job->toMessageId])->delete();

            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));

            $run = MemoryAnalysisRun::query()
                ->where('conversation_id', $job->conversationId)
                ->where('type', MemoryAnalysisRunType::Turn)
                ->orderByDesc('id')
                ->first();
            $this->assertNotNull($run);
            $this->assertSame(MemoryAnalysisRunStatus::Failed, $run->status);
            $this->assertSame(AsyncFailureCategory::MissingSource->value, $run->last_error);
            $this->assertFalse((bool) $run->metadata['retryable']);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_left_telegram_group_is_stale_source_without_retry(): void
    {
        $user = null;
        $chatId = '-910009901';

        try {
            $user = $this->createTemporaryUser();
            $group = $this->makeGroup($user, $chatId);
            $run = TelegramGroupAnalysisRun::query()->create([
                'telegram_group_id' => $group->id,
                'analysis_type' => TelegramGroupAnalysisRunType::RangeBundle,
                'from_at' => CarbonImmutable::now()->subDay(),
                'to_at' => CarbonImmutable::now(),
                'status' => TelegramGroupAnalysisRunStatus::Queued,
                'attempts' => 0,
                'idempotency_key' => hash('sha256', 'stale-group-'.$group->id),
                'metadata' => [],
            ]);
            $group->forceFill(['status' => TelegramGroupStatus::Left])->save();

            $job = new AnalyzeTelegramGroupRangeJob((int) $run->id);
            $job->handle(app(GroupAnalysisService::class));

            $run->refresh();
            $this->assertSame(TelegramGroupAnalysisRunStatus::Failed, $run->status);
            $this->assertSame(AsyncFailureCategory::StaleSource->value, $run->last_error);
            $this->assertFalse((bool) $run->metadata['retryable']);
        } finally {
            $this->deleteTestTelegramGroup($chatId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_expired_attachment_source_is_skipped_not_retried(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            Storage::fake(ChatAttachmentConfig::disk());
            $conversation = app(ConversationService::class)->createPersonal($user, 'Shot');
            $message = $this->addUserMessage($conversation, 'скрин');
            $attachment = MessageAttachment::query()->create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'kind' => MessageAttachment::KIND_IMAGE,
                'retention_class' => AttachmentRetentionClass::Ephemeral,
                'summary_status' => AttachmentSummaryStatus::Pending,
                'expires_at' => now()->subHour(),
                'storage_disk' => ChatAttachmentConfig::disk(),
                'storage_path' => 'chat-attachments/'.$user->id.'/gone.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 12,
            ]);

            $job = new SummarizeMessageAttachmentJob((int) $attachment->id);
            $job->handle(app(AttachmentVisionSummaryService::class));

            $attachment->refresh();
            $this->assertSame(AttachmentSummaryStatus::NotRequired, $attachment->summary_status);
            $this->assertSame(AsyncFailureCategory::StaleSource->value, $attachment->metadata['error_category'] ?? null);
            $this->assertSame(0, (int) $attachment->purge_failure_count);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_provider_auth_does_not_retry_on_attachment_summary(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $fake = $this->bindFake();
            $fake->exception = new AiProviderException('Gemini chat request failed with status 401');
            $user = $this->createTemporaryUser();
            Storage::fake(ChatAttachmentConfig::disk());
            $path = 'chat-attachments/'.$user->id.'/shot.jpg';
            Storage::disk(ChatAttachmentConfig::disk())->put($path, 'image-bytes');
            $conversation = app(ConversationService::class)->createPersonal($user, 'Vision');
            $message = $this->addUserMessage($conversation, 'скрин');
            $attachment = MessageAttachment::query()->create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'kind' => MessageAttachment::KIND_IMAGE,
                'retention_class' => AttachmentRetentionClass::Ephemeral,
                'summary_status' => AttachmentSummaryStatus::Pending,
                'storage_disk' => ChatAttachmentConfig::disk(),
                'storage_path' => $path,
                'mime_type' => 'image/jpeg',
                'size_bytes' => 11,
            ]);

            $job = new SummarizeMessageAttachmentJob((int) $attachment->id);
            $job->handle(app(AttachmentVisionSummaryService::class));

            $attachment->refresh();
            $this->assertSame(AttachmentSummaryStatus::Failed, $attachment->summary_status);
            $this->assertSame(AsyncFailureCategory::ProviderAuth->value, $attachment->metadata['error_category'] ?? null);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_stuck_running_recovery_ignores_fresh_rows(): void
    {
        $user = null;

        try {
            $this->freezeTime();
            $user = $this->createTemporaryUser();
            [$job, $fresh] = $this->turnJob($user);
            $fresh->forceFill([
                'status' => MemoryAnalysisRunStatus::Processing,
                'started_at' => now(),
            ])->save();

            $stale = MemoryAnalysisRun::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $job->conversationId,
                'from_message_id' => $job->fromMessageId,
                'to_message_id' => $job->toMessageId,
                'type' => MemoryAnalysisRunType::Backfill,
                'status' => MemoryAnalysisRunStatus::Processing,
                'attempts' => 1,
                'started_at' => now()->subHours(2),
                'metadata' => [],
            ]);
            MemoryAnalysisRun::query()->whereKey($stale->id)->update([
                'updated_at' => now()->subHours(2),
            ]);

            $counts = app(StaleAsyncRunRecovery::class)->recover(30, true);

            $this->assertSame(1, $counts['memory']);
            $this->assertSame(MemoryAnalysisRunStatus::Processing, $fresh->refresh()->status);
            $this->assertSame(MemoryAnalysisRunStatus::Failed, $stale->refresh()->status);
            $this->assertSame('stale_running', $stale->metadata['error_code'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_retry_command_defaults_to_dry_run(): void
    {
        $user = null;

        try {
            Http::preventStrayRequests();
            Bus::fake([
                AnalyzeConversationTurnJob::class,
                UpdateConversationSummaryJob::class,
                ProcessTelegramUpdate::class,
            ]);
            $user = $this->createTemporaryUser();
            [$job, $run] = $this->turnJob($user);
            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Failed,
                'attempts' => 1,
                'last_error' => AsyncFailureCategory::ProviderTimeout->value,
                'metadata' => [
                    'error_category' => AsyncFailureCategory::ProviderTimeout->value,
                    'error_code' => 'http_429',
                    'retryable' => true,
                ],
            ])->save();

            $this->artisan('jarvis:memory:retry-failed', [
                '--limit' => 5,
                '--category' => AsyncFailureCategory::ProviderTimeout->value,
            ])->assertSuccessful();

            Bus::assertNothingDispatched();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_retry_command_excludes_permanent_failures(): void
    {
        $user = null;

        try {
            Bus::fake([AnalyzeConversationTurnJob::class, UpdateConversationSummaryJob::class]);
            $user = $this->createTemporaryUser();
            [$job, $run] = $this->turnJob($user);
            $run->forceFill([
                'status' => MemoryAnalysisRunStatus::Failed,
                'attempts' => 3,
                'last_error' => AsyncFailureCategory::ProviderSafety->value,
                'metadata' => [
                    'error_category' => AsyncFailureCategory::ProviderSafety->value,
                    'error_code' => 'provider_safety',
                    'retryable' => false,
                ],
            ])->save();

            $this->artisan('jarvis:memory:retry-failed', [
                '--execute' => true,
                '--limit' => 5,
                '--category' => AsyncFailureCategory::ProviderSafety->value,
            ])->expectsOutputToContain('eligible=0')
                ->assertSuccessful();

            Bus::assertNothingDispatched();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_diagnostics_do_not_expose_payload_or_transcript(): void
    {
        $this->artisan('jarvis:reliability:report')
            ->doesntExpectOutputToContain('Запомни')
            ->doesntExpectOutputToContain('payload')
            ->doesntExpectOutputToContain('transcript')
            ->doesntExpectOutputToContain('prompt')
            ->assertSuccessful();
    }

    public function test_reliability_jobs_do_not_dispatch_telegram_writes(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $this->bindFake();
            Bus::fake([ProcessTelegramUpdate::class]);
            $user = $this->createTemporaryUser();
            [$job] = $this->turnJob($user);
            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));

            Bus::assertNotDispatched(ProcessTelegramUpdate::class);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    private function bindFake(): FakeAiChatGateway
    {
        $fake = new FakeAiChatGateway;
        $this->app->instance(AiChatGateway::class, $fake);

        return $fake;
    }

    /**
     * @return array{0: AnalyzeConversationTurnJob, 1: MemoryAnalysisRun}
     */
    private function turnJob($user): array
    {
        $conversation = app(ConversationService::class)->createPersonal($user, 'Reliability turn');
        [$from, $to] = $this->addDialogue($conversation, 'reliability source', 'ok');
        $job = new AnalyzeConversationTurnJob((int) $user->id, (int) $conversation->id, (int) $from->id, (int) $to->id);
        $run = MemoryAnalysisRun::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'type' => MemoryAnalysisRunType::Turn,
            'from_message_id' => $from->id,
            'to_message_id' => $to->id,
        ]);
        $run->fill([
            'user_id' => $user->id,
            'status' => MemoryAnalysisRunStatus::Pending,
            'attempts' => 0,
        ])->save();

        return [$job, $run];
    }

    /**
     * @return array{0: Message, 1: Message}
     */
    private function addDialogue(Conversation $conversation, string $userBody, string $assistantBody): array
    {
        $from = $this->addUserMessage($conversation, $userBody);
        $to = Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'role' => MessageRole::Assistant,
            'channel' => MessageChannel::Web,
            'body' => $assistantBody,
            'message_type' => MessageType::Text,
            'parent_message_id' => $from->id,
            'occurred_at' => now(),
        ]);

        return [$from, $to];
    }

    private function addUserMessage(Conversation $conversation, string $body): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Web,
            'body' => $body,
            'message_type' => MessageType::Text,
            'occurred_at' => now(),
        ]);
    }

    private function makeGroup($user, string $chatId): TelegramGroup
    {
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'kind' => ConversationKind::Group,
            'title' => 'Reliability group',
            'status' => ConversationStatus::Active,
            'last_activity_at' => now(),
        ]);

        return TelegramGroup::query()->create([
            'telegram_chat_id' => $chatId,
            'conversation_id' => $conversation->id,
            'title' => 'Reliability group',
            'chat_type' => 'supergroup',
            'status' => TelegramGroupStatus::Connected,
            'timezone' => 'Europe/Rome',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'message_count' => 0,
            'settings' => ['mode' => TelegramGroup::MODE_PERSIST_ONLY],
        ]);
    }
}
