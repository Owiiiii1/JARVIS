<?php

namespace App\Services\Conversations;

use App\Enums\MessageChannel;
use DateTimeInterface;

final readonly class ChannelContext
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public MessageChannel $channel,
        public ?string $channelMessageId = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?array $metadata = null,
        public string $inboundModality = 'text',
    ) {}
}
