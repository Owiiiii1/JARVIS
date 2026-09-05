<?php

namespace App\Services\Telegram\DTO;

final readonly class TelegramDownloadedVoiceFile
{
    public function __construct(
        public string $absolutePath,
        public int $byteLength,
        public ?int $reportedFileSize = null,
    ) {}
}
