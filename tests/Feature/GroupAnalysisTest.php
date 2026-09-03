<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupAnalysisRunType;
use App\Enums\TelegramGroupKnowledgeStatus;
use App\Enums\TelegramGroupKnowledgeType;
use App\Enums\UserRole;
use App\Jobs\AnalyzeTelegramGroupRangeJob;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\TelegramBotSetting;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;
use App\Models\User;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Groups\GroupAnalysisRunService;
use App\Services\Groups\GroupMessageChunker;
use App\Services\Groups\GroupTimeRangeService;
use App\Services\Memory\MemoryKeyNormalizer;
use App\Services\Memory\PersonalMemoryRetriever;
use App\Services\Projects\ProjectService;
use App\Services\Projects\ProjectContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class GroupAnalysisTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_group_knowledge_schema(): void
    {
        $this->assertTrue(Schema::hasTable('telegram_group_knowledge'));
        $this->assertTrue(Schema::hasTable('telegram_group_knowledge_sources'));
        $this->assertTrue(Schema::hasTable('telegram_group_knowledge_revisions'));
        $this->assertTrue(Schema::hasTable('telegram_group_analysis_runs'));
        $this->assertTrue(Schema::hasColumns('telegram_group_knowledge', [
            'telegram_group_id', 'type', 'title', 'content', 'structured_data', 'confidence', 'status',
            'valid_from', 'valid_until', 'source_from_message_id', 'source_to_message_id',
            'generated_by_provider', 'generated_by_model', 'generated_at', 'metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('telegram_group_knowledge_sources', [
            'knowledge_id', 'message_id', 'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('telegram_group_analysis_runs', [
            'telegram_group_id', 'analysis_type', 'from_at', 'to_at', 'status', 'attempts',
            'provider', 'model', 'started_at', 'completed_at', 'last_error', 'idempotency_key', 'metadata',
        ]));
    }

    public function test_owner_can_start_analysis_and_user_is_denied(): void
    {
        $chatId = '-910001020';
        $user = null;
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912001', 'hello analysis', 912001, 201);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $message = Message::query()->where('telegram_group_id', $group->id)->first();
            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'summary' => ['content' => 'Hello summary', 'confidence' => 0.8, 'source_message_ids' => [$message->id]],
            ]);

            $this->actingAs($owner)
                ->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today'])
                ->assertRedirect();

            $this->assertSame(TelegramGroupAnalysisRunStatus::Completed, TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->first()->status);
            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', TelegramGroupKnowledgeType::Summary)->count());

            $user = $this->createTemporaryUser();
            $this->actingAs($user)
                ->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today'])
                ->assertForbidden();
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_timezone_today_and_dst_range(): void
    {
        $chatId = '-910001021';

        try {
            $this->postGroupText($chatId, '912011', 'tz', 912011, 211);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $group->forceFill(['timezone' => 'Europe/Rome'])->save();
            $ranges = app(GroupTimeRangeService::class);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-28 12:00:00', 'UTC'));
            $normal = $ranges->today($group);
            $this->assertEquals(24, (int) round($normal['from']->diffInHours($normal['to'])));

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-29 12:00:00', 'UTC'));
            $dst = $ranges->today($group);
            $this->assertEquals(23, (int) round($dst['from']->diffInHours($dst['to'])));
            $this->assertSame('UTC', $dst['from']->timezoneName);
            $this->assertSame('UTC', $dst['to']->timezoneName);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_empty_range_skips_ai(): void
    {
        $chatId = '-910001022';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912021', 'later', 912021, 221);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            Message::query()->where('telegram_group_id', $group->id)->update([
                'occurred_at' => now()->subDays(3),
            ]);

            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);

            $run = TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->first();
            $this->assertSame(TelegramGroupAnalysisRunStatus::Completed, $run->status);
            $this->assertTrue((bool) ($run->metadata['no_data'] ?? false));
            $this->assertSame([], $fake->analysisCalls());
            $this->assertSame(0, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_small_range_is_single_chunk_and_persists_types(): void
    {
        $chatId = '-910001023';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912031', 'seed', 912031, 231);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $message = Message::query()->where('telegram_group_id', $group->id)->first();
            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'summary' => ['content' => 'Release planning', 'confidence' => 0.9, 'source_message_ids' => [$message->id]],
                'decisions' => [[
                    'content' => 'Release on Friday',
                    'confidence' => 0.91,
                    'source_message_ids' => [$message->id],
                ]],
                'tasks' => [[
                    'content' => 'Prepare mockups',
                    'assignee_text' => 'Ivan',
                    'due_at_local' => now()->addDay()->timezone($group->effectiveTimezone($owner->timezone))->format('Y-m-d'),
                    'confidence' => 0.88,
                    'source_message_ids' => [$message->id],
                ]],
                'events' => [[
                    'content' => 'API outage mentioned',
                    'confidence' => 0.7,
                    'source_message_ids' => [$message->id],
                ]],
            ]);

            $remindersBefore = Reminder::query()->count();
            $memoriesBefore = Memory::query()->count();

            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);

            $this->assertCount(1, $fake->analysisCalls());
            $this->assertStringNotContainsString('reducing chunk analyses', $fake->analysisCalls()[0]['request']->messages[0]->content);
            $this->assertSame(AiRoleKey::OwnerAnalysis->value, $fake->analysisCalls()[0]['role_key']);

            foreach ([TelegramGroupKnowledgeType::Summary, TelegramGroupKnowledgeType::Decision, TelegramGroupKnowledgeType::Task, TelegramGroupKnowledgeType::EventFact] as $type) {
                $row = TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', $type)->first();
                $this->assertNotNull($row);
                $this->assertTrue($row->sources()->exists());
                $this->assertTrue($row->sources()->where('message_id', $message->id)->exists());
            }

            $this->assertSame($remindersBefore, Reminder::query()->count());
            $this->assertSame($memoriesBefore, Memory::query()->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_large_range_is_chunked_and_reduce_is_called(): void
    {
        $chatId = '-910001024';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            config(['group_analysis.max_messages_per_chunk' => 2, 'group_analysis.max_chars_per_chunk' => 100000]);

            foreach (range(1, 5) as $index) {
                $this->postGroupText($chatId, '912041', 'chunk '.$index, 912040 + $index, 240 + $index);
            }

            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $ids = Message::query()->where('telegram_group_id', $group->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $chunkSize = 2;
            foreach (array_chunk($ids, $chunkSize) as $chunkIds) {
                $fake->script[] = new AiChatResponse(
                    text: $this->analysisJson($chunkIds[0], [
                        'summary' => ['content' => 'Chunked', 'confidence' => 0.8, 'source_message_ids' => [$chunkIds[0]]],
                        'decisions' => [[
                            'content' => 'Ship Friday',
                            'confidence' => 0.9,
                            'source_message_ids' => [$chunkIds[0]],
                        ]],
                    ]),
                    provider: 'fake',
                    model: 'fake-model',
                    finishReason: 'stop',
                );
            }
            $fake->script[] = new AiChatResponse(
                text: $this->analysisJson($ids[0], [
                    'summary' => ['content' => 'Reduced summary', 'confidence' => 0.85, 'source_message_ids' => [$ids[0]]],
                    'decisions' => [[
                        'content' => 'Ship Friday',
                        'confidence' => 0.9,
                        'source_message_ids' => [$ids[0]],
                    ]],
                ]),
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);

            $calls = $fake->analysisCalls();
            $this->assertGreaterThan(1, count($calls));
            $this->assertStringContainsString('reducing chunk analyses', $calls[count($calls) - 1]['request']->messages[0]->content);
            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', TelegramGroupKnowledgeType::Decision)->count());
        } finally {
            config([
                'group_analysis.max_messages_per_chunk' => 40,
                'group_analysis.max_chars_per_chunk' => 12000,
                'group_analysis.max_chunks_per_run' => 8,
            ]);
            $this->restoreAiRoleSettings();
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_foreign_source_id_is_rejected_and_malformed_writes_nothing(): void
    {
        $chatA = '-910001025';
        $chatB = '-910001026';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatA, '912051', 'alpha', 912051, 251);
            $this->postGroupText($chatB, '912052', 'beta', 912052, 252);
            $groupA = TelegramGroup::query()->where('telegram_chat_id', $chatA)->first();
            $foreignId = (int) Message::query()->where('telegram_group_id', TelegramGroup::query()->where('telegram_chat_id', $chatB)->value('id'))->value('id');

            $fake->analysisResponseText = $this->analysisJson($foreignId, [
                'decisions' => [[
                    'content' => 'Should not persist',
                    'confidence' => 0.9,
                    'source_message_ids' => [$foreignId],
                ]],
            ]);
            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $groupA), ['preset' => 'today']);
            $this->assertSame(0, TelegramGroupKnowledge::query()->where('telegram_group_id', $groupA->id)->count());
            $this->assertSame(TelegramGroupAnalysisRunStatus::Failed, TelegramGroupAnalysisRun::query()->where('telegram_group_id', $groupA->id)->first()->status);

            $fake->analysisResponseText = 'not-json';
            $this->actingAs($owner)->post(route('telegram-groups.analysis.retry', [$groupA->id, TelegramGroupAnalysisRun::query()->where('telegram_group_id', $groupA->id)->value('id')]));
            $this->assertSame(0, TelegramGroupKnowledge::query()->where('telegram_group_id', $groupA->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTestTelegramGroup($chatA);
            $this->deleteTestTelegramGroup($chatB);
        }
    }

    public function test_group_knowledge_never_bleeds_into_personal_memory_or_context(): void
    {
        $chatId = '-910001027';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $this->enableRoleForTests(AiRoleKey::OwnerConversation);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912061', 'GROUPKNOWLEDGESECRETTOKEN', 912061, 261);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $message = Message::query()->where('telegram_group_id', $group->id)->first();
            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'summary' => ['content' => 'GROUPKNOWLEDGESECRETTOKEN', 'confidence' => 0.9, 'source_message_ids' => [$message->id]],
                'decisions' => [[
                    'content' => 'GROUPKNOWLEDGESECRETTOKEN',
                    'confidence' => 0.9,
                    'source_message_ids' => [$message->id],
                ]],
            ]);
            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);

            $this->assertSame(0, Memory::query()->where('content', 'like', '%GROUPKNOWLEDGESECRETTOKEN%')->count());

            $conversation = app(ConversationService::class)->createPersonal($owner, 'jarvis-test-group-analysis');
            $package = app(PersonalMemoryRetriever::class)->retrieve($owner, $conversation, 'GROUPKNOWLEDGESECRETTOKEN');
            $this->assertFalse(collect($package->memories)->contains(fn ($memory) => str_contains((string) $memory->content, 'GROUPKNOWLEDGESECRETTOKEN')));

            $built = app(ConversationContextBuilder::class)->build($owner, $conversation, app(\App\Services\Ai\AiConfigurationResolver::class)->resolveConversation($owner));
            $this->assertStringNotContainsString('GROUPKNOWLEDGESECRETTOKEN', $built['system_prompt']);
        } finally {
            $this->restoreAiRoleSettings();
            $conversationId = \App\Models\Conversation::query()->where('user_id', $owner->id)->where('title', 'jarvis-test-group-analysis')->value('id');
            if ($conversationId) {
                Message::query()->where('conversation_id', $conversationId)->delete();
                \App\Models\Conversation::query()->whereKey($conversationId)->delete();
            }
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_dedupe_supersede_retry_and_run_idempotency(): void
    {
        $chatId = '-910001028';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912071', 'decision text', 912071, 271);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $message = Message::query()->where('telegram_group_id', $group->id)->first();
            $key = MemoryKeyNormalizer::memoryKey(null, 'Release on Friday');

            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'decisions' => [[
                    'content' => 'Release on Friday',
                    'confidence' => 0.8,
                    'source_message_ids' => [$message->id],
                ]],
            ]);
            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);
            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', TelegramGroupKnowledgeType::Decision)->count());

            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);
            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', TelegramGroupKnowledgeType::Decision)->where('status', TelegramGroupKnowledgeStatus::Active)->count());

            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'decisions' => [[
                    'content' => 'Release on Monday',
                    'confidence' => 0.9,
                    'source_message_ids' => [$message->id],
                    'supersedes_normalized_key' => $key,
                ]],
            ]);
            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);
            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', TelegramGroupKnowledgeType::Decision)->where('status', TelegramGroupKnowledgeStatus::Superseded)->count());
            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('status', TelegramGroupKnowledgeStatus::Active)->where('type', TelegramGroupKnowledgeType::Decision)->count());
            $active = TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('status', TelegramGroupKnowledgeStatus::Active)->where('type', TelegramGroupKnowledgeType::Decision)->first();
            $this->assertStringContainsString('Monday', $active->content);
            $this->assertNotNull($active->supersedes_id);

            $from = now()->subHour()->toImmutable();
            $to = now()->addHour()->toImmutable();
            $key = TelegramGroupAnalysisRun::idempotencyKey((int) $group->id, TelegramGroupAnalysisRunType::RangeBundle->value, $from, $to);
            $seed = TelegramGroupAnalysisRun::query()->create([
                'telegram_group_id' => $group->id,
                'analysis_type' => TelegramGroupAnalysisRunType::RangeBundle,
                'from_at' => $from,
                'to_at' => $to,
                'status' => TelegramGroupAnalysisRunStatus::Queued,
                'attempts' => 0,
                'idempotency_key' => $key,
            ]);
            $first = app(GroupAnalysisRunService::class)->queue($owner, $group, $from, $to);
            $second = app(GroupAnalysisRunService::class)->queue($owner, $group, $from, $to);
            $this->assertSame($seed->id, $first->id);
            $this->assertSame($first->id, $second->id);
            $this->assertSame(TelegramGroupAnalysisRunStatus::Queued, $first->status);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_project_context_includes_bounded_group_knowledge_without_raw(): void
    {
        $chatId = '-910001029';
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $name = 'jarvis-test-project-'.Str::lower(Str::random(8));
        $project = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912081', 'project group raw should not leak', 912081, 281);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $message = Message::query()->where('telegram_group_id', $group->id)->first();
            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'summary' => ['content' => 'Derived group summary', 'confidence' => 0.9, 'source_message_ids' => [$message->id]],
                'decisions' => [[
                    'content' => 'Derived group decision',
                    'confidence' => 0.9,
                    'source_message_ids' => [$message->id],
                ]],
            ]);
            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);

            $project = app(ProjectService::class)->create($owner, $name, 'tmp');
            app(ProjectService::class)->attachGroup($owner, $project, $group);
            $context = app(ProjectContextService::class)->context($owner, $project);

            $this->assertArrayHasKey('group_knowledge', $context);
            $this->assertTrue(collect($context['group_knowledge'])->contains(fn ($row) => $row['content'] === 'Derived group summary'));
            $this->assertArrayHasKey('groups', $context);
            $this->assertArrayNotHasKey('messages', $context['groups'][0]);
            $encoded = json_encode($context);
            $this->assertStringNotContainsString('project group raw should not leak', $encoded);
        } finally {
            $this->restoreAiRoleSettings();
            if ($project !== null) {
                $project->telegramGroups()->detach();
                $project->delete();
            }
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_group_inbound_does_not_auto_analyze(): void
    {
        $chatId = '-910001030';

        try {
            Bus::fake([AnalyzeTelegramGroupRangeJob::class]);
            $this->postGroupText($chatId, '912091', 'no auto analysis', 912091, 291);
            Bus::assertNotDispatched(AnalyzeTelegramGroupRangeJob::class);
        } finally {
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_chunker_respects_config_caps(): void
    {
        config(['group_analysis.max_messages_per_chunk' => 2, 'group_analysis.max_chunks_per_run' => 2, 'group_analysis.max_chars_per_chunk' => 10000]);
        $lines = [];
        foreach (range(1, 9) as $i) {
            $lines[] = ['id' => $i, 'line' => 'msg '.$i, 'chars' => 5];
        }
        $chunks = app(GroupMessageChunker::class)->chunk($lines);
        $this->assertCount(2, $chunks);
        $this->assertCount(2, $chunks[0]);
        config([
            'group_analysis.max_messages_per_chunk' => 40,
            'group_analysis.max_chunks_per_run' => 8,
            'group_analysis.max_chars_per_chunk' => 12000,
        ]);
    }

    public function test_retry_does_not_duplicate_knowledge(): void
    {
        $chatId = '-910001031';
        $owner = User::query()->where('role', UserRole::Owner)->first();

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $this->postGroupText($chatId, '912101', 'retry seed', 912101, 301);
            $group = TelegramGroup::query()->where('telegram_chat_id', $chatId)->first();
            $message = Message::query()->where('telegram_group_id', $group->id)->first();
            $fake->analysisResponseText = 'broken';
            $this->actingAs($owner)->post(route('telegram-groups.analysis.store', $group), ['preset' => 'today']);
            $run = TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->first();
            $this->assertSame(TelegramGroupAnalysisRunStatus::Failed, $run->status);

            $fake->analysisResponseText = $this->analysisJson($message->id, [
                'decisions' => [[
                    'content' => 'Only once',
                    'confidence' => 0.9,
                    'source_message_ids' => [$message->id],
                ]],
            ]);
            $this->actingAs($owner)->post(route('telegram-groups.analysis.retry', [$group->id, $run->id]));
            $this->actingAs($owner)->post(route('telegram-groups.analysis.retry', [$group->id, $run->id]));

            $this->assertSame(1, TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->where('type', TelegramGroupKnowledgeType::Decision)->count());
            $this->assertSame(1, TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    private function bindFake(): FakeAiChatGateway
    {
        $fake = new FakeAiChatGateway;
        $this->app->forgetInstance(AiChatGateway::class);
        $this->app->forgetInstance(\App\Services\Groups\GroupAnalysisService::class);
        $this->app->instance(AiChatGateway::class, $fake);
        $this->app->instance(
            \App\Services\Groups\GroupAnalysisService::class,
            $this->app->make(\App\Services\Groups\GroupAnalysisService::class, ['gateway' => $fake]),
        );

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function analysisJson(int $messageId, array $overrides = []): string
    {
        $payload = array_replace_recursive([
            'summary' => ['content' => 'Summary', 'confidence' => 0.8, 'source_message_ids' => [$messageId]],
            'decisions' => [],
            'tasks' => [],
            'events' => [],
        ], $overrides);

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function postGroupText(string $chatId, string $fromId, string $text, int $updateId, int $messageId): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Jarvis Analysis Test'],
                'from' => ['id' => (int) $fromId, 'is_bot' => false, 'first_name' => 'Test'],
                'text' => $text,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
        ])->assertOk();
    }

    private function webhookSecret(): string
    {
        $setting = TelegramBotSetting::query()->first();
        $this->assertNotNull($setting);

        return (string) $setting->webhook_secret;
    }
}
