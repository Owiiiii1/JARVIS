<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TelegramBotSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Conversations\ConversationService;
use App\Services\Telegram\TelegramIdentityState;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class ConversationsCoreTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_conversations_and_messages_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('conversations'));
        $this->assertTrue(Schema::hasTable('messages'));
        $this->assertTrue(Schema::hasColumns('conversations', ['user_id', 'kind', 'title', 'status', 'last_activity_at']));
        $this->assertTrue(Schema::hasColumns('messages', ['conversation_id', 'user_id', 'role', 'channel', 'body', 'message_type', 'channel_message_id', 'parent_message_id', 'occurred_at']));
    }

    public function test_paired_start_creates_default_conversation_once(): void
    {
        $user = null;
        $externalUserId = '920101';

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);

            $this->postTelegramUpdate($externalUserId, '/start', 920101);
            $this->postTelegramUpdate($externalUserId, '/start', 920102);

            $this->assertSame(1, Conversation::query()->where('user_id', $user->id)->count());
            $this->assertSame(Conversation::DEFAULT_TITLE, Conversation::query()->where('user_id', $user->id)->value('title'));
            $this->assertSame(0, Message::query()->where('user_id', $user->id)->where('role', MessageRole::User)->count());
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_menu_commands_are_not_persisted_as_user_messages(): void
    {
        $user = null;
        $externalUserId = '920102';

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, '/start', 920201);
            $this->postTelegramUpdate($externalUserId, 'Чаты', 920202);
            $this->postTelegramUpdate($externalUserId, 'Текущий чат', 920203);
            $this->postTelegramUpdate($externalUserId, 'Новый чат', 920204);

            $this->assertSame(0, Message::query()->where('user_id', $user->id)->where('role', MessageRole::User)->count());
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_new_chat_telegram_state_creates_and_selects_conversation(): void
    {
        $user = null;
        $externalUserId = '920103';

        try {
            $user = $this->createTemporaryUser();
            $identity = $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, '/start', 920301);
            $this->postTelegramUpdate($externalUserId, 'Новый чат', 920302);

            $this->assertTrue(app(TelegramIdentityState::class)->isAwaitingNewChatTitle($identity->fresh()));

            $this->postTelegramUpdate($externalUserId, 'Тест', 920303);

            $created = Conversation::query()->where('user_id', $user->id)->where('title', 'Тест')->first();
            $this->assertNotNull($created);
            $this->assertSame($created->id, $identity->fresh()->active_conversation_id);
            $this->assertFalse(app(TelegramIdentityState::class)->isAwaitingNewChatTitle($identity->fresh()));
            $this->assertSame(0, Message::query()->where('user_id', $user->id)->where('role', MessageRole::User)->count());
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_select_chat_callback_sets_active_owned_conversation_only(): void
    {
        $userA = null;
        $userB = null;
        $externalA = '920104';
        $externalB = '920105';

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $identityA = $this->createTemporaryTelegramIdentity($userA, $externalA);
            $this->createTemporaryTelegramIdentity($userB, $externalB);
            $service = app(ConversationService::class);
            $own = $service->createPersonal($userA, 'Work');
            $foreign = $service->createPersonal($userB, 'Secret');
            $service->setActiveConversation($identityA, $service->getOrCreateDefault($userA));

            $this->postTelegramCallback($externalA, 'c:'.$own->id, 920401);
            $this->assertSame($own->id, $identityA->fresh()->active_conversation_id);

            $this->postTelegramCallback($externalA, 'c:'.$foreign->id, 920402);
            $this->assertSame($own->id, $identityA->fresh()->active_conversation_id);
        } finally {
            $this->deleteTelegramIdentity($externalA);
            $this->deleteTelegramIdentity($externalB);
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_paired_text_persists_in_active_chat_and_is_idempotent(): void
    {
        $user = null;
        $externalUserId = '920106';
        $fake = new FakeAiChatGateway;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->app->instance(AiChatGateway::class, $fake);

            $user = $this->createTemporaryUser();
            $identity = $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, '/start', 920501);
            $this->postTelegramUpdate($externalUserId, 'Привет', 920502, messageId: 77);
            $this->postTelegramUpdate($externalUserId, 'Привет', 920502, messageId: 77);

            $conversation = Conversation::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($conversation);
            $this->assertSame($conversation->id, $identity->fresh()->active_conversation_id);
            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->count());
            $this->assertSame('Привет', Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->value('body'));
            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->count());
            $this->assertSame(1, count($fake->calls));
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_cabinet_shows_telegram_created_conversations(): void
    {
        $user = null;
        $externalUserId = '920107';

        try {
            $user = $this->createTemporaryUser();
            $this->createTemporaryTelegramIdentity($user, $externalUserId);
            $this->postTelegramUpdate($externalUserId, '/start', 920601);
            $this->postTelegramUpdate($externalUserId, 'Новый чат', 920602);
            $this->postTelegramUpdate($externalUserId, 'Cabinet Visible', 920603);

            $response = $this->actingAs($user)->followingRedirects()->get('/cabinet');
            $response->assertOk();
            $this->assertStringContainsString('Cabinet Visible', $response->getContent());
        } finally {
            $this->deleteTelegramIdentity($externalUserId);
            $this->deleteTemporaryUser($user);
        }
    }

    private function webhookSecret(): string
    {
        $setting = TelegramBotSetting::query()->first();
        $this->assertNotNull($setting);
        $this->assertNotEmpty($setting->webhook_secret);

        return (string) $setting->webhook_secret;
    }

    private function postTelegramUpdate(string $externalUserId, string $text, int $updateId, int $messageId = 1): void
    {
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

    private function postTelegramCallback(string $externalUserId, string $data, int $updateId): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => (string) $updateId,
                'from' => ['id' => (int) $externalUserId, 'is_bot' => false, 'first_name' => 'Test'],
                'chat_instance' => '1',
                'data' => $data,
                'message' => [
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => (int) $externalUserId, 'type' => 'private'],
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Bot'],
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $this->webhookSecret(),
        ])->assertOk();
    }
}
