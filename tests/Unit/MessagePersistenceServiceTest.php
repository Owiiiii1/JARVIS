<?php

namespace Tests\Unit;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Message;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\MessagePersistenceService;
use App\Services\Conversations\PersistMessageData;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class MessagePersistenceServiceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_persist_inbound_and_idempotent_channel_message_id(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $service = app(MessagePersistenceService::class);
            $data = new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::User,
                channel: MessageChannel::Telegram,
                messageType: MessageType::Text,
                body: 'hello',
                channelMessageId: 'tg-1',
            );

            $first = $service->persistInbound($data);
            $second = $service->persistInbound($data);

            $this->assertTrue($first->created);
            $this->assertFalse($second->created);
            $this->assertSame($first->message->id, $second->message->id);
            $this->assertSame(1, Message::query()->where('conversation_id', $conversation->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
