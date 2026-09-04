<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupAnalysisRunType;
use App\Enums\TelegramGroupKnowledgeStatus;
use App\Enums\TelegramGroupKnowledgeType;
use App\Enums\TelegramGroupStatus;
use App\Enums\UserRole;
use App\Jobs\AnalyzeTelegramGroupRangeJob;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;
use App\Models\TelegramGroupKnowledgeSource;
use App\Models\TelegramGroupParticipant;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Groups\GroupTimeRangeService;
use App\Services\Memory\PersonalMemoryRetriever;
use App\Services\Projects\ProjectService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\SearchGroupKnowledgeTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class GroupKnowledgeSearchTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_owner_receives_tool_and_user_does_not(): void
    {
        $ownerLike = null;
        $user = null;

        try {
            $ownerLike = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $ownerChat = app(ConversationService::class)->createPersonal($ownerLike, 'Основной');
            $userChat = app(ConversationService::class)->createPersonal($user, 'Основной');
            $registry = app(ToolRegistry::class);

            $ownerTools = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor(new ToolExecutionContext($ownerLike, $ownerChat)));
            $userTools = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor(new ToolExecutionContext($user, $userChat)));

            $this->assertContains(SearchGroupKnowledgeTool::NAME, $ownerTools);
            $this->assertContains(CreateReminderTool::NAME, $ownerTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $ownerTools);
            $this->assertContains(GetProjectContextTool::NAME, $ownerTools);
            $this->assertNotContains(SearchGroupKnowledgeTool::NAME, $userTools);
            $this->assertContains(CreateReminderTool::NAME, $userTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $userTools);
        } finally {
            $this->deleteTemporaryUser($ownerLike);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_forged_user_execution_is_denied(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $chat = app(ConversationService::class)->createPersonal($user, 'Основной');
            $denied = app(ToolRegistry::class)->execute(
                new ToolCall('c1', SearchGroupKnowledgeTool::NAME, ['query' => 'today']),
                new ToolExecutionContext($user, $chat),
            );

            $this->assertFalse($denied->success);
            $this->assertSame('tool_not_available', $denied->payload['error']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_group_resolution_project_filters_and_type_filters(): void
    {
        $owner = null;
        $chatA = '-910015001';
        $chatB = '-910015002';
        $chatC = '-910015003';

        try {
            config(['group_search.queue_missing_analysis' => false]);
            $owner = $this->temporaryOwner();
            $dev = $this->makeGroup($owner, $chatA, 'Jarvis Search Dev Team', 'Europe/Rome');
            $ops = $this->makeGroup($owner, $chatB, 'Jarvis Search Ops', 'Europe/Rome');
            $other = $this->makeGroup($owner, $chatC, 'Jarvis Search Other', 'Europe/Rome');
            $range = app(GroupTimeRangeService::class)->today($dev);
            $this->addKnowledge($dev, TelegramGroupKnowledgeType::Decision, 'Ship Friday', $range);
            $this->addKnowledge($dev, TelegramGroupKnowledgeType::Task, 'Prepare mockups', $range, ['assignee_text' => 'Ivan']);
            $this->addKnowledge($ops, TelegramGroupKnowledgeType::Decision, 'Unrelated ops decision', $range);
            $this->addRaw($other, 'secret other group raw', now());

            $project = app(ProjectService::class)->create($owner, 'jarvis-search-'.Str::lower(Str::random(6)));
            app(ProjectService::class)->attachGroup($owner, $project, $dev);

            $context = new ToolExecutionContext($owner, app(ConversationService::class)->createPersonal($owner, 'Основной'));
            $tool = app(SearchGroupKnowledgeTool::class);

            $exact = $tool->execute(new ToolCall('t1', SearchGroupKnowledgeTool::NAME, [
                'query' => 'Friday',
                'group' => 'Jarvis Search Dev Team',
            ]), $context);
            $this->assertTrue($exact->success);
            $this->assertSame('Jarvis Search Dev Team', $exact->payload['groups'][0]['name']);
            $this->assertNotEmpty($exact->payload['groups'][0]['decisions']);
            $this->assertStringNotContainsString('secret other group raw', json_encode($exact->payload));

            $fuzzy = $tool->execute(new ToolCall('t2', SearchGroupKnowledgeTool::NAME, [
                'query' => 'Friday',
                'group' => 'search dev team',
            ]), $context);
            $this->assertTrue($fuzzy->success);
            $this->assertSame('Jarvis Search Dev Team', $fuzzy->payload['groups'][0]['name']);

            $this->makeGroup($owner, '-910015004', 'Jarvis Search Crew', 'Europe/Rome');
            $this->makeGroup($owner, '-910015005', 'Jarvis Search Crew Chat', 'Europe/Rome');
            $ambiguous = $tool->execute(new ToolCall('t3', SearchGroupKnowledgeTool::NAME, [
                'query' => 'Friday',
                'group' => 'Search Crew',
            ]), $context);
            $this->assertTrue($ambiguous->success);
            $this->assertSame('ambiguous_group', $ambiguous->payload['error']);
            $this->assertGreaterThanOrEqual(2, count($ambiguous->payload['candidates']));

            $projected = $tool->execute(new ToolCall('t4', SearchGroupKnowledgeTool::NAME, [
                'query' => 'decision',
                'project' => $project->name,
            ]), $context);
            $this->assertTrue($projected->success);
            $this->assertCount(1, $projected->payload['groups']);
            $this->assertSame('Jarvis Search Dev Team', $projected->payload['groups'][0]['name']);
            $this->assertStringNotContainsString('Unrelated ops decision', json_encode($projected->payload));

            $tasks = $tool->execute(new ToolCall('t6', SearchGroupKnowledgeTool::NAME, [
                'query' => 'mockups',
                'group' => 'Jarvis Search Dev Team',
                'types' => ['task'],
            ]), $context);
            $this->assertTrue($tasks->success);
            $this->assertNotEmpty($tasks->payload['groups'][0]['tasks']);
            $this->assertSame([], $tasks->payload['groups'][0]['decisions']);
            $this->assertSame('Ivan', $tasks->payload['groups'][0]['tasks'][0]['assignee_text']);

            $decisions = $tool->execute(new ToolCall('t7', SearchGroupKnowledgeTool::NAME, [
                'query' => 'Friday',
                'group' => 'Jarvis Search Dev Team',
                'types' => ['decision'],
            ]), $context);
            $this->assertNotEmpty($decisions->payload['groups'][0]['decisions']);
            $this->assertSame([], $decisions->payload['groups'][0]['tasks']);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTestTelegramGroup($chatA);
            $this->deleteTestTelegramGroup($chatB);
            $this->deleteTestTelegramGroup($chatC);
            $this->deleteTestTelegramGroup('-910015004');
            $this->deleteTestTelegramGroup('-910015005');
        }
    }

    public function test_today_uses_each_group_timezone_and_dst(): void
    {
        $owner = null;
        $romeId = '-910015010';
        $laId = '-910015011';

        try {
            config(['group_search.queue_missing_analysis' => false]);
            $owner = $this->temporaryOwner();
            $rome = $this->makeGroup($owner, $romeId, 'Jarvis Search Rome TZ', 'Europe/Rome');
            $la = $this->makeGroup($owner, $laId, 'Jarvis Search LA TZ', 'America/Los_Angeles');
            $project = app(ProjectService::class)->create($owner, 'jarvis-tz-'.Str::lower(Str::random(6)));
            app(ProjectService::class)->attachGroup($owner, $project, $rome);
            app(ProjectService::class)->attachGroup($owner, $project, $la);

            $pivot = CarbonImmutable::parse('2026-09-04 05:00:00', 'UTC');
            $this->addRaw($rome, 'rome morning unique token', $pivot);
            $this->addRaw($la, 'la morning unique token', $pivot);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 12:00:00', 'UTC'));
            $result = $this->search($owner, [
                'query' => 'unique token',
                'project' => $project->name,
                'range' => 'today',
                'include_raw_if_needed' => true,
            ]);

            $byName = collect($result->payload['groups'])->keyBy('name');
            $this->assertTrue(collect($byName['Jarvis Search Rome TZ']['raw_snippets'])->contains(fn ($row) => str_contains($row['snippet'], 'rome morning')));
            $this->assertFalse(collect($byName['Jarvis Search LA TZ']['raw_snippets'] ?? [])->contains(fn ($row) => str_contains($row['snippet'], 'la morning')));

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-29 12:00:00', 'UTC'));
            $dst = app(GroupTimeRangeService::class)->today($rome);
            $this->assertEquals(23, (int) round($dst['from']->diffInHours($dst['to'])));
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($owner);
            $this->deleteTestTelegramGroup($romeId);
            $this->deleteTestTelegramGroup($laId);
        }
    }

    public function test_derived_first_status_filters_provenance_and_raw_bounds(): void
    {
        $owner = null;
        $chatId = '-910015020';
        $foreignId = '-910015021';

        try {
            config(['group_search.queue_missing_analysis' => false]);
            $owner = $this->temporaryOwner();
            $group = $this->makeGroup($owner, $chatId, 'Jarvis Search Derived', 'Europe/Rome');
            $foreign = $this->makeGroup($owner, $foreignId, 'Jarvis Search Foreign', 'Europe/Rome');
            $range = app(GroupTimeRangeService::class)->today($group);
            $active = $this->addKnowledge($group, TelegramGroupKnowledgeType::Decision, 'Use derived-first unique phrase', $range);
            $this->addKnowledge($group, TelegramGroupKnowledgeType::Decision, 'Old superseded decision', $range, [], TelegramGroupKnowledgeStatus::Superseded);
            $this->addKnowledge($group, TelegramGroupKnowledgeType::Decision, 'Disputed leftover', $range, [], TelegramGroupKnowledgeStatus::Disputed);
            $source = $this->addRaw($group, 'source snippet about derived-first unique phrase', now());
            TelegramGroupKnowledgeSource::query()->create([
                'knowledge_id' => $active->id,
                'message_id' => $source->id,
                'created_at' => now(),
            ]);
            $this->addRaw($foreign, 'foreign raw must never leak xyz', now());
            foreach (range(1, 25) as $i) {
                $this->addRaw($group, 'padding raw '.$i.' derived-first unique phrase', now());
            }

            $result = $this->search($owner, [
                'query' => 'derived-first unique phrase',
                'group' => 'Jarvis Search Derived',
            ]);

            $this->assertTrue($result->success);
            $encoded = json_encode($result->payload);
            $this->assertStringContainsString('Use derived-first unique phrase', $encoded);
            $this->assertStringNotContainsString('Old superseded decision', $encoded);
            $this->assertStringNotContainsString('Disputed leftover', $encoded);
            $this->assertStringNotContainsString('foreign raw must never leak xyz', $encoded);
            $this->assertNotEmpty($result->payload['groups'][0]['decisions'][0]['sources']);
            $this->assertLessThanOrEqual((int) config('group_search.max_raw_snippets_per_group'), count($result->payload['groups'][0]['raw_snippets']));
            $this->assertArrayNotHasKey('telegram_chat_id', $result->payload['groups'][0]);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTestTelegramGroup($chatId);
            $this->deleteTestTelegramGroup($foreignId);
        }
    }

    public function test_participant_raw_fallback_and_empty_and_malformed_dates(): void
    {
        $owner = null;
        $chatId = '-910015022';

        try {
            config(['group_search.queue_missing_analysis' => false]);
            $owner = $this->temporaryOwner();
            $group = $this->makeGroup($owner, $chatId, 'Jarvis Search Ivan', 'Europe/Rome');
            TelegramGroupParticipant::query()->create([
                'telegram_group_id' => $group->id,
                'telegram_user_id' => '900001',
                'username' => 'ivan_dev',
                'first_name' => 'Ivan',
                'display_name' => 'Ivan Petrov',
                'is_bot' => false,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            $this->addRaw($group, 'API freeze after lunch', now(), 'Ivan Petrov', 'ivan_dev');
            $this->addRaw($group, 'unrelated weather chat', now(), 'Maria', 'maria');

            $found = $this->search($owner, [
                'query' => 'Что Иван говорил про API',
                'group' => 'Jarvis Search Ivan',
                'include_raw_if_needed' => true,
            ]);
            $this->assertTrue(collect($found->payload['groups'][0]['raw_snippets'])->contains(fn ($row) => str_contains($row['snippet'], 'API freeze')));
            $this->assertSame('Ivan Petrov', $found->payload['groups'][0]['raw_snippets'][0]['sender']);

            $empty = $this->search($owner, [
                'query' => 'nonexistent-token-zzzx',
                'group' => 'Jarvis Search Ivan',
            ]);
            $this->assertTrue($empty->success);
            $this->assertSame('No matching group knowledge/raw messages in requested scope.', $empty->payload['message']);

            $bad = $this->search($owner, [
                'query' => 'dates',
                'group' => 'Jarvis Search Ivan',
                'range' => 'custom',
                'date_from' => '04-09-2026',
                'date_to' => '05-09-2026',
            ]);
            $this->assertFalse($bad->success);
            $this->assertSame('needs_clarification', $bad->payload['error']);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_coverage_queue_staleness_and_no_wait(): void
    {
        $owner = null;
        $chatId = '-910015023';

        try {
            Bus::fake([AnalyzeTelegramGroupRangeJob::class]);
            config(['group_search.queue_missing_analysis' => true]);
            $owner = $this->temporaryOwner();
            $group = $this->makeGroup($owner, $chatId, 'Jarvis Search Queue', 'Europe/Rome');
            $this->addRaw($group, 'need analysis about release window', now()->subHours(2));

            $first = $this->search($owner, [
                'query' => 'release window',
                'group' => 'Jarvis Search Queue',
                'range' => 'today',
            ]);
            $this->assertSame('queued', $first->payload['groups'][0]['analysis_status']);
            $this->assertNotEmpty($first->payload['groups'][0]['raw_snippets']);
            Bus::assertDispatched(AnalyzeTelegramGroupRangeJob::class);
            $this->assertSame(1, TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->count());

            $second = $this->search($owner, [
                'query' => 'release window',
                'group' => 'Jarvis Search Queue',
                'range' => 'today',
            ]);
            $this->assertSame(1, TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->count());
            $this->assertSame('queued', $second->payload['groups'][0]['analysis_status']);

            $range = app(GroupTimeRangeService::class)->today($group);
            $run = TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->first();
            $run->forceFill([
                'status' => TelegramGroupAnalysisRunStatus::Completed,
                'from_at' => $range['from'],
                'to_at' => $range['to'],
                'completed_at' => now()->subMinutes(30),
                'idempotency_key' => TelegramGroupAnalysisRun::idempotencyKey((int) $group->id, TelegramGroupAnalysisRunType::RangeBundle->value, $range['from'], $range['to']),
            ])->save();
            Message::query()->where('telegram_group_id', $group->id)->update([
                'created_at' => now()->subHours(2),
                'occurred_at' => now()->subHours(2),
            ]);
            $this->addKnowledge($group, TelegramGroupKnowledgeType::Summary, 'Completed summary about release window', $range, [], TelegramGroupKnowledgeStatus::Active, $run);

            Bus::fake([AnalyzeTelegramGroupRangeJob::class]);
            $reused = $this->search($owner, [
                'query' => 'release window',
                'group' => 'Jarvis Search Queue',
                'range' => 'today',
            ]);
            $this->assertSame('available', $reused->payload['groups'][0]['analysis_status']);
            Bus::assertNotDispatched(AnalyzeTelegramGroupRangeJob::class);

            $this->addRaw($group, 'new message after analysis about release window', now());
            $stale = $this->search($owner, [
                'query' => 'release window',
                'group' => 'Jarvis Search Queue',
                'range' => 'today',
            ]);
            $this->assertContains($stale->payload['groups'][0]['analysis_status'], ['partial', 'queued']);
            $this->assertTrue(collect($stale->payload['groups'][0]['raw_snippets'])->contains(
                fn ($row) => str_contains($row['snippet'], 'new message after analysis'),
            ));
        } finally {
            config(['group_search.queue_missing_analysis' => true]);
            $this->deleteTemporaryUser($owner);
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_no_personal_memory_or_default_context_and_existing_tools_remain(): void
    {
        $owner = null;
        $user = null;
        $chatId = '-910015024';

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerConversation);
            config(['group_search.queue_missing_analysis' => false]);
            $owner = $this->temporaryOwner();
            $user = $this->createTemporaryUser();
            $group = $this->makeGroup($owner, $chatId, 'Jarvis Search Isolation', 'Europe/Rome');
            $range = app(GroupTimeRangeService::class)->today($group);
            $this->addKnowledge($group, TelegramGroupKnowledgeType::Decision, 'ISOLATION-GROUP-FACT-UNIQUE', $range);
            $personal = app(ConversationService::class)->createPersonal($owner, 'Основной');
            $memoriesBefore = Memory::query()->where('user_id', $owner->id)->count();
            $remindersBefore = Reminder::query()->where('user_id', $owner->id)->count();

            $this->search($owner, [
                'query' => 'ISOLATION-GROUP-FACT-UNIQUE',
                'group' => 'Jarvis Search Isolation',
            ]);

            $this->assertSame($memoriesBefore, Memory::query()->where('user_id', $owner->id)->count());
            $this->assertSame($remindersBefore, Reminder::query()->where('user_id', $owner->id)->count());

            $inbound = Message::query()->create([
                'conversation_id' => $personal->id,
                'user_id' => $owner->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Привет',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::OwnerConversation->value)->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build($owner, $personal, $configuration, $inbound);
            $this->assertStringNotContainsString('ISOLATION-GROUP-FACT-UNIQUE', $context['system_prompt']);
            $package = app(PersonalMemoryRetriever::class)->retrieve($owner, $personal, 'Привет');
            $this->assertFalse(collect($package->memories)->contains(fn ($memory) => str_contains($memory->content, 'ISOLATION-GROUP-FACT-UNIQUE')));

            $historyChat = app(ConversationService::class)->createPersonal($owner, 'History');
            Message::query()->create([
                'conversation_id' => $historyChat->id,
                'user_id' => $owner->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Personal Niagara decision',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $history = app(SearchConversationHistoryTool::class)->execute(
                new ToolCall('h1', SearchConversationHistoryTool::NAME, ['query' => 'Niagara']),
                new ToolExecutionContext($owner, $personal),
            );
            $this->assertTrue($history->success);

            $project = app(ProjectService::class)->create($owner, 'jarvis-iso-'.Str::lower(Str::random(6)));
            $projectContext = app(GetProjectContextTool::class)->execute(
                new ToolCall('p1', GetProjectContextTool::NAME, ['project' => $project->name]),
                new ToolExecutionContext($owner, $personal),
            );
            $this->assertTrue($projectContext->success);

            $reminder = app(CreateReminderTool::class)->execute(
                new ToolCall('r1', CreateReminderTool::NAME, [
                    'text' => 'isolation reminder',
                    'run_at_local' => now()->addHour()->timezone('Europe/Rome')->toIso8601String(),
                ]),
                new ToolExecutionContext($owner, $personal),
            );
            $this->assertContains($reminder->payload['error'] ?? 'ok', ['telegram_not_connected', 'invalid_arguments', 'ok']);
            if ($reminder->success) {
                Reminder::query()->where('user_id', $owner->id)->where('text', 'isolation reminder')->delete();
            }
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    public function test_multi_tool_loop_and_web_cabinet_path(): void
    {
        $owner = null;
        $chatId = '-910015025';

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerConversation);
            config(['group_search.queue_missing_analysis' => false]);
            $fake = $this->bindFake();
            $owner = $this->temporaryOwner();
            $group = $this->makeGroup($owner, $chatId, 'Jarvis Search Loop', 'Europe/Rome');
            $range = app(GroupTimeRangeService::class)->today($group);
            $this->addKnowledge($group, TelegramGroupKnowledgeType::Decision, 'Loop decision about deploy', $range);
            $conversation = app(ConversationService::class)->createPersonal($owner, 'Основной');
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $owner->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'cabinet history about Niagara',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);

            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('g1', SearchGroupKnowledgeTool::NAME, [
                    'query' => 'deploy',
                    'group' => 'Jarvis Search Loop',
                ])],
            );
            $fake->script[] = new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [new ToolCall('h1', SearchConversationHistoryTool::NAME, [
                    'query' => 'Niagara',
                ])],
            );
            $fake->script[] = new AiChatResponse(
                text: 'Combined cabinet answer',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'stop',
            );

            $turn = app(ConversationTurnService::class)->handleUserMessage(
                $owner,
                $conversation,
                'Что решили в группе и что было в личке про Niagara?',
                new ChannelContext(MessageChannel::Web, 'm15-web-1'),
            );

            $this->assertSame('Combined cabinet answer', $turn->assistantMessage?->body);
            $this->assertSame(MessageChannel::Web, $turn->inbound->channel);
            $this->assertGreaterThanOrEqual(3, count($fake->conversationCalls()));
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($owner);
            $this->deleteTestTelegramGroup($chatId);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function search(User $owner, array $arguments): ToolResult
    {
        $chat = app(ConversationService::class)->listForUser($owner)->first()
            ?? app(ConversationService::class)->createPersonal($owner, 'Основной');

        return app(SearchGroupKnowledgeTool::class)->execute(
            new ToolCall('s1', SearchGroupKnowledgeTool::NAME, $arguments),
            new ToolExecutionContext($owner, $chat),
        );
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    private function makeGroup(User $owner, string $chatId, string $title, string $timezone): TelegramGroup
    {
        $conversation = Conversation::query()->create([
            'user_id' => $owner->id,
            'kind' => ConversationKind::Group,
            'title' => $title,
            'status' => ConversationStatus::Active,
            'last_activity_at' => now(),
        ]);

        return TelegramGroup::query()->create([
            'telegram_chat_id' => $chatId,
            'conversation_id' => $conversation->id,
            'title' => $title,
            'chat_type' => 'supergroup',
            'status' => TelegramGroupStatus::Connected,
            'timezone' => $timezone,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'message_count' => 0,
            'settings' => ['mode' => TelegramGroup::MODE_PERSIST_ONLY],
        ]);
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $range
     * @param  array<string, mixed>  $structured
     */
    private function addKnowledge(
        TelegramGroup $group,
        TelegramGroupKnowledgeType $type,
        string $content,
        array $range,
        array $structured = [],
        TelegramGroupKnowledgeStatus $status = TelegramGroupKnowledgeStatus::Active,
        ?TelegramGroupAnalysisRun $run = null,
    ): TelegramGroupKnowledge {
        return TelegramGroupKnowledge::query()->create([
            'telegram_group_id' => $group->id,
            'analysis_run_id' => $run?->id,
            'type' => $type,
            'title' => null,
            'content' => $content,
            'structured_data' => $structured === [] ? null : $structured,
            'confidence' => 0.9,
            'status' => $status,
            'valid_from' => $range['from'],
            'valid_until' => $range['to'],
            'generated_at' => now(),
        ]);
    }

    private function addRaw(
        TelegramGroup $group,
        string $body,
        mixed $occurredAt,
        string $senderName = 'Test',
        ?string $username = null,
    ): Message {
        return Message::query()->create([
            'conversation_id' => $group->conversation_id,
            'telegram_group_id' => $group->id,
            'user_id' => $group->conversation?->user_id ?? Conversation::query()->whereKey($group->conversation_id)->value('user_id'),
            'role' => MessageRole::User,
            'channel' => MessageChannel::Telegram,
            'body' => $body,
            'message_type' => MessageType::Text,
            'sender_name' => $senderName,
            'sender_username' => $username,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function bindFake(): FakeAiChatGateway
    {
        $fake = new FakeAiChatGateway;
        $this->app->forgetInstance(AiChatGateway::class);
        $this->app->instance(AiChatGateway::class, $fake);

        return $fake;
    }
}
