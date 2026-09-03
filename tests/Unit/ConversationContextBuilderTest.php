<?php

namespace Tests\Unit;

use App\Enums\AiRoleKey;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\AiRoleSetting;
use App\Models\Message;
use App\Models\UserAiSetting;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\MessagePersistenceService;
use App\Services\Conversations\PersistMessageData;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ConversationContextBuilderTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_context_uses_current_conversation_only_and_includes_general_prompt(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversations = app(ConversationService::class);
            $active = $conversations->createPersonal($user, 'Основной');
            $other = $conversations->createPersonal($user, 'Тест');
            $persistence = app(MessagePersistenceService::class);

            UserAiSetting::query()->create([
                'user_id' => $user->id,
                'general_prompt' => 'Speak like a concise engineer.',
            ]);

            $persistence->persistInbound(new PersistMessageData(
                conversation: $other,
                role: MessageRole::User,
                channel: MessageChannel::Telegram,
                messageType: MessageType::Text,
                body: 'secret other chat',
                channelMessageId: 'other-1',
            ));

            $persistence->persistSystem(new PersistMessageData(
                conversation: $active,
                role: MessageRole::System,
                channel: MessageChannel::Telegram,
                messageType: MessageType::System,
                body: 'Сообщение сохранено в чате «Основной». AI будет подключён на следующем этапе.',
            ));

            $inbound = $persistence->persistInbound(new PersistMessageData(
                conversation: $active,
                role: MessageRole::User,
                channel: MessageChannel::Telegram,
                messageType: MessageType::Text,
                body: 'current inbound',
                channelMessageId: 'active-1',
            ))->message;

            $configuration = AiRoleSetting::query()
                ->where('role_key', AiRoleKey::UserConversation->value)
                ->firstOrFail();

            $context = app(ConversationContextBuilder::class)->build(
                $user,
                $active,
                $configuration,
                $inbound,
            );

            $this->assertStringContainsString((string) $configuration->system_prompt, $context['system_prompt']);
            $this->assertStringContainsString('Speak like a concise engineer.', $context['system_prompt']);
            $this->assertStringContainsString('Current user local time:', $context['system_prompt']);
            $this->assertStringContainsString('User timezone:', $context['system_prompt']);
            $this->assertStringContainsString('Europe/Rome', $context['system_prompt']);

            $bodies = array_map(static fn ($message): string => $message->content, $context['messages']);
            $this->assertContains('current inbound', $bodies);
            $this->assertNotContains('secret other chat', $bodies);
            $this->assertFalse(collect($bodies)->contains(fn (string $body): bool => str_contains($body, 'Сообщение сохранено')));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_technical_system_placeholders_are_excluded(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::System,
                'channel' => MessageChannel::Telegram,
                'body' => 'Не удалось получить ответ от AI. Попробуйте ещё раз позже.',
                'message_type' => MessageType::System,
                'metadata' => ['technical' => true],
                'occurred_at' => now(),
            ]);

            $configuration = AiRoleSetting::query()
                ->where('role_key', AiRoleKey::UserConversation->value)
                ->firstOrFail();

            $context = app(ConversationContextBuilder::class)->build($user, $conversation, $configuration);

            $this->assertSame([], $context['messages']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_unfinished_previous_user_turns_are_excluded_from_new_turn(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Telegram,
                'body' => 'Напомни через две минуты проверить почту',
                'message_type' => MessageType::Text,
                'metadata' => ['ai' => ['status' => 'pending']],
                'occurred_at' => now()->subMinute(),
            ]);

            $current = Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Telegram,
                'body' => 'Ты тут?',
                'message_type' => MessageType::Text,
                'metadata' => ['ai' => ['status' => 'pending']],
                'occurred_at' => now(),
            ]);

            $configuration = AiRoleSetting::query()
                ->where('role_key', AiRoleKey::UserConversation->value)
                ->firstOrFail();

            $context = app(ConversationContextBuilder::class)->build(
                $user,
                $conversation,
                $configuration,
                $current,
            );

            $bodies = array_map(static fn ($message): string => $message->content, $context['messages']);

            $this->assertSame(['Ты тут?'], $bodies);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
