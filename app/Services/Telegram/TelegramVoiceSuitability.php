<?php

namespace App\Services\Telegram;

final readonly class TelegramVoiceSuitability
{
    public function __construct(
        public bool $suitable,
        public string $spokenText,
        public ?string $reason = null,
    ) {}
}
