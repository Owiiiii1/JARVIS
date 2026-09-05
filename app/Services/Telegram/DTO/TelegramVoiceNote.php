<?php

namespace App\Services\Telegram\DTO;

use DateTimeInterface;

final readonly class TelegramVoiceNote
{
    public function __construct(
        public string $fileId,
        public string $channelMessageId,
        public int $durationSeconds,
        public ?string $fileUniqueId = null,
        public ?string $mimeType = null,
        public ?int $fileSize = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {}
}
