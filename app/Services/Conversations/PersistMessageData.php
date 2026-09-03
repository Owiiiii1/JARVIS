<?php

namespace App\Services\Conversations;

use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Conversation;
use DateTimeInterface;

final readonly class PersistMessageData
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public Conversation $conversation,
        public MessageRole $role,
        public MessageChannel $channel,
        public MessageType $messageType,
        public ?string $body = null,
        public ?string $channelMessageId = null,
        public ?int $parentMessageId = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?array $metadata = null,
        public ?int $telegramGroupId = null,
        public ?string $senderExternalId = null,
        public ?string $senderUsername = null,
        public ?string $senderName = null,
        public ?string $replyToChannelMessageId = null,
        public ?string $threadId = null,
        public ?DateTimeInterface $editedAt = null,
    ) {}
}
