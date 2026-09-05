<?php

namespace App\Services\Telegram\DTO;

final readonly class TelegramExistingInbound
{
    public function __construct(
        public string $body,
        public bool $hasAssistantReply,
    ) {}
}
