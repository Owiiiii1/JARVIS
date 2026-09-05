<?php

namespace App\Services\Telegram\Contracts;

use App\Services\Telegram\DTO\TelegramDownloadedVoiceFile;

interface DownloadsTelegramVoice
{
    public function download(string $fileId, string $absolutePath): TelegramDownloadedVoiceFile;
}
