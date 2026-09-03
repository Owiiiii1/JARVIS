<?php

namespace App\Services\Conversations;

use App\Models\Message;

final readonly class ConversationAiTurnResult
{
    public function __construct(
        public bool $skipped = false,
        public ?Message $assistantMessage = null,
        public ?string $errorText = null,
    ) {}

    public function replyText(): ?string
    {
        if ($this->assistantMessage !== null) {
            return (string) $this->assistantMessage->body;
        }

        return $this->errorText;
    }
}
