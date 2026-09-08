<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MessageRole;
use App\Enums\ToolOperationClass;
use App\Enums\UserRole;
use App\Models\ChannelIdentity;
use App\Models\Message;
use App\Models\TelegramBotSetting;
use App\Models\User;
use App\Services\Ai\AgentToolLoop;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Tools\ToolRegistry;
use App\Services\Users\UserCapability;
use Illuminate\Support\Sleep;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\CountingFakeTool;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class AgentRuntimeReliabilityTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    private ?User $turnUser = null;

    private ?string $turnExternalId = null;

    protected function tearDown(): void
    {
        $this->endTurn();
        parent::tearDown();
    }

    public function test_several_storage_reads_then_final_answer(): void
    {
        $storage = new CountingFakeTool('get_storage_file', payload: [
            'file_id' => 'cnc-1',
            'excerpt' => 'G1 A90',
        ]);
        $fake = $this->scriptedGateway([
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1']),
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1', 'start' => 1]),
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1', 'start' => 2]),
            $this->textResponse('По файлу ось A сдвигается на 0.02 на программу.'),
        ]);

        $this->beginTurn($fake, [$storage]);
        $this->postTelegramUpdate('941001', 'Посчитай сдвиг оси A', 941001, 11);

        $this->assertSame(3, $storage->executions);
        $this->assertSame('По файлу ось A сдвигается на 0.02 на программу.', $this->assistantBody());
        $this->assertFalse($this->assistantHasTechnicalError());
    }

    public function test_repeated_storage_call_exits_loop_and_synthesizes(): void
    {
        config(['context_budget.no_progress_tool_rounds' => 2]);
        $storage = new CountingFakeTool('read_storage_file_chunks', payload: [
            'file_id' => 'cnc-1',
            'chunks' => [['index' => 0, 'text' => 'G1 A90']],
        ]);
        $loop = $this->toolResponse('read_storage_file_chunks', ['file_id' => 'cnc-1', 'start' => 0]);
        $fake = $this->scriptedGateway([
            $loop,
            $loop,
            $loop,
            $this->textResponse('По файлу удалось установить сдвиг оси A, но для Z данных недостаточно.'),
        ]);

        $this->beginTurn($fake, [$storage]);
        $this->postTelegramUpdate('941002', 'Проанализируй файл', 941002, 12);

        $this->assertSame(1, $storage->executions);
        $this->assertSame([], $this->lastRequest($fake)->tools);
        $this->assertStringContainsString('Do not call tools', $this->lastRequest($fake)->systemPrompt);
        $this->assertStringContainsString('оси A', $this->assistantBody());
        $this->assertFalse($this->assistantHasTechnicalError());
    }

    public function test_hard_tool_limit_still_forces_final_synthesis(): void
    {
        config([
            'context_budget.max_tool_rounds' => 3,
            'context_budget.no_progress_tool_rounds' => 99,
        ]);
        $storage = new CountingFakeTool('get_storage_file', payload: ['file_id' => 'cnc-1', 'excerpt' => 'A']);
        $fake = $this->scriptedGateway([
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1']),
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1', 'start' => 1]),
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1', 'start' => 2]),
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1', 'start' => 3]),
            $this->textResponse('Сводка по прочитанным кускам: ось A смещается.'),
        ]);

        $this->beginTurn($fake, [$storage]);
        $this->postTelegramUpdate('941003', 'Разбери программу', 941003, 13);

        $this->assertSame([], $this->lastRequest($fake)->tools);
        $this->assertStringContainsString('Do not call tools', $this->lastRequest($fake)->systemPrompt);
        $this->assertSame('Сводка по прочитанным кускам: ось A смещается.', $this->assistantBody());
    }

    public function test_partial_answer_when_one_tool_fails(): void
    {
        $storage = new CountingFakeTool('get_storage_file', payload: ['file_id' => 'cnc-1', 'excerpt' => 'A90']);
        $calendar = new CountingFakeTool(
            'list_calendar_events',
            ToolOperationClass::Read,
            UserCapability::GOOGLE_CALENDAR,
            'google',
            ['error' => 'unavailable'],
            true,
        );
        $fake = $this->scriptedGateway([
            new AiChatResponse(
                text: '',
                provider: 'fake',
                model: 'fake-model',
                finishReason: 'tool_calls',
                toolCalls: [
                    new ToolCall('s1', 'get_storage_file', ['file_id' => 'cnc-1']),
                    new ToolCall('c1', 'list_calendar_events', ['query' => 'today']),
                ],
            ),
            $this->textResponse(''),
            $this->textResponse('По файлу ось A смещается. Календарь сейчас получить не удалось.'),
        ]);

        $this->beginTurn($fake, [$storage, $calendar], asOwner: true);
        $this->postTelegramUpdate('941004', 'Сверь файл и календарь', 941004, 14);

        $this->assertSame(1, $storage->executions);
        $this->assertSame(1, $calendar->executions);
        $this->assertStringContainsString('По файлу', $this->assistantBody());
        $this->assertStringContainsString('Календарь', $this->assistantBody());
        $this->assertFalse($this->assistantHasTechnicalError());
    }

    public function test_empty_provider_response_after_reads_is_recovered(): void
    {
        Sleep::fake();
        $storage = new CountingFakeTool('get_storage_file', payload: ['file_id' => 'cnc-1']);
        $fake = $this->scriptedGateway([
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1']),
            static function (): never {
                throw new AiEmptyResponseException;
            },
            $this->textResponse('По файлу сдвиг оси A около 0.02.'),
        ]);

        $this->beginTurn($fake, [$storage]);
        $this->postTelegramUpdate('941005', 'Посчитай сдвиг', 941005, 15);

        $this->assertSame(1, $storage->executions);
        $this->assertSame('По файлу сдвиг оси A около 0.02.', $this->assistantBody());
    }

    public function test_presence_check_does_not_resume_previous_tool_plan(): void
    {
        $storage = new CountingFakeTool('get_storage_file', payload: ['file_id' => 'cnc-1']);
        $fake = new FakeAiChatGateway;
        $fake->script = [
            $this->toolResponse('get_storage_file', ['file_id' => 'cnc-1']),
            $this->textResponse('По файлу ось A смещается.'),
        ];

        $this->beginTurn($fake, [$storage]);
        $this->postTelegramUpdate('941006', 'Посчитай сдвиг оси A', 941006, 16);
        $this->assertSame(1, $storage->executions);

        $fake->script = [];
        $fake->responseText = 'Да, я здесь.';
        $this->postTelegramUpdate('941006', 'эй', 941007, 17);

        $this->assertSame(1, $storage->executions);
        $this->assertSame([], $this->lastRequest($fake)->tools);
        $this->assertSame('Да, я здесь.', $this->assistantBody());
    }

    public function test_external_write_is_not_repeated_on_provider_retry(): void
    {
        Sleep::fake();
        $write = new CountingFakeTool(
            'create_github_issue',
            ToolOperationClass::Write,
            UserCapability::GITHUB,
            'github',
            ['id' => 7, 'title' => 'axis'],
        );
        $fake = $this->scriptedGateway([
            $this->toolResponse('create_github_issue', ['repository' => 'owl/jarvis', 'title' => 'axis']),
            static function (): never {
                throw new AiEmptyResponseException;
            },
            $this->textResponse('Создал issue по оси A.'),
        ]);

        $this->beginTurn($fake, [$write], asOwner: true);
        $this->postTelegramUpdate('941008', 'Создай issue по оси A', 941008, 18);

        $this->assertSame(1, $write->executions);
        $this->assertSame('Создал issue по оси A.', $this->assistantBody());
    }

    public function test_forced_synthesis_instruction_is_present(): void
    {
        $this->assertStringContainsString('Do not call tools', AgentToolLoop::SYNTHESIS_INSTRUCTION);
        $this->assertStringContainsString('Partial answers are useful', AgentToolLoop::SYNTHESIS_INSTRUCTION);
    }

    /**
     * @param  list<CountingFakeTool>  $tools
     */
    private function beginTurn(FakeAiChatGateway $fake, array $tools, bool $asOwner = false): void
    {
        $this->snapshotAiRoleSettings();
        $this->enableRoleForTests(AiRoleKey::UserConversation);
        $this->enableRoleForTests(AiRoleKey::OwnerConversation);
        $this->app->instance(AiChatGateway::class, $fake);
        $this->app->instance(ToolRegistry::class, new ToolRegistry($tools));

        $this->turnUser = $this->createTemporaryUser();
        if ($asOwner) {
            $this->turnUser->forceFill(['role' => UserRole::Owner])->save();
        }
    }

    private function endTurn(): void
    {
        if ($this->turnExternalId !== null) {
            $this->deleteTelegramIdentity($this->turnExternalId);
        }

        $this->restoreAiRoleSettings();
        $this->deleteTemporaryUser($this->turnUser);
        $this->turnUser = null;
        $this->turnExternalId = null;
    }

    /**
     * @param  list<AiChatResponse|\Closure>  $script
     */
    private function scriptedGateway(array $script): FakeAiChatGateway
    {
        $fake = new FakeAiChatGateway;
        $fake->script = $script;

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function toolResponse(string $name, array $arguments): AiChatResponse
    {
        return new AiChatResponse(
            text: '',
            provider: 'fake',
            model: 'fake-model',
            finishReason: 'tool_calls',
            toolCalls: [new ToolCall($name.'-'.md5(json_encode($arguments) ?: $name), $name, $arguments)],
        );
    }

    private function textResponse(string $text): AiChatResponse
    {
        return new AiChatResponse(
            text: $text,
            provider: 'fake',
            model: 'fake-model',
            finishReason: 'stop',
        );
    }

    private function lastRequest(FakeAiChatGateway $fake): AiChatRequest
    {
        $calls = $fake->conversationCalls();

        return $calls[array_key_last($calls)]['request'];
    }

    private function assistantBody(): string
    {
        $this->assertNotNull($this->turnUser);

        return (string) Message::query()
            ->where('user_id', $this->turnUser->id)
            ->where('role', MessageRole::Assistant)
            ->orderByDesc('id')
            ->value('body');
    }

    private function assistantHasTechnicalError(): bool
    {
        $body = mb_strtolower($this->assistantBody());

        return str_contains($body, 'техническая ошибка') || str_contains($body, 'при формировании ответа');
    }

    private function webhookSecret(): string
    {
        $setting = TelegramBotSetting::query()->first();
        $this->assertNotNull($setting);

        return (string) $setting->webhook_secret;
    }

    private function postTelegramUpdate(string $externalUserId, string $text, int $updateId, int $messageId = 1): void
    {
        $this->turnExternalId = $externalUserId;

        if ($this->turnUser !== null && ChannelIdentity::findTelegramByExternalUserId($externalUserId) === null) {
            $this->createTemporaryTelegramIdentity($this->turnUser, $externalUserId);
        }

        $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => (int) $externalUserId, 'type' => 'private', 'first_name' => 'Test'],
                'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'text' => $text,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
        ])->assertOk();
    }
}
