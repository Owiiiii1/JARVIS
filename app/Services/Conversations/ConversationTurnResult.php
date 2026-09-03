<?php

namespace App\Services\Conversations;

use App\Models\Message;

final readonly class ConversationTurnResult
{
    public function __construct(
        public Message $inbound,
        public bool $created,
        public ?Message $assistantMessage = null,
        public ?string $errorText = null,
        public bool $skipped = false,
    ) {}

    public function replyText(): ?string
    {
        if ($this->assistantMessage !== null) {
            return (string) $this->assistantMessage->body;
        }

        return $this->errorText;
    }
}
