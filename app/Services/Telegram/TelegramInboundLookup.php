<?php

namespace App\Services\Telegram;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Models\Message;
use App\Services\Conversations\MessagePersistenceService;
use App\Services\Telegram\Contracts\LooksUpTelegramInbound;
use App\Services\Telegram\DTO\TelegramExistingInbound;

final class TelegramInboundLookup implements LooksUpTelegramInbound
{
    public function __construct(
        private readonly MessagePersistenceService $messages,
    ) {}

    public function find(int $conversationId, string $channelMessageId): ?TelegramExistingInbound
    {
        $message = $this->messages->findByChannelMessage(
            MessageChannel::Telegram->value,
            $conversationId,
            $channelMessageId,
        );

        if ($message === null) {
            return null;
        }

        $hasAssistant = Message::query()
            ->where('parent_message_id', $message->id)
            ->where('role', MessageRole::Assistant)
            ->exists();

        return new TelegramExistingInbound(
            body: trim((string) $message->body),
            hasAssistantReply: $hasAssistant,
        );
    }
}
