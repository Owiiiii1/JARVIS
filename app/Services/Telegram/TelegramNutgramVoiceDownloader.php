<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Contracts\DownloadsTelegramVoice;
use App\Services\Telegram\DTO\TelegramDownloadedVoiceFile;
use App\Services\Voice\Exceptions\VoiceException;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use Throwable;

final class TelegramNutgramVoiceDownloader implements DownloadsTelegramVoice
{
    public function __construct(
        private readonly Nutgram $bot,
    ) {}

    public function download(string $fileId, string $absolutePath): TelegramDownloadedVoiceFile
    {
        try {
            $file = $this->bot->getFile($fileId);
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram getFile failed.', 0, $exception);
        }

        if ($file === null || ! filled($file->file_path ?? null)) {
            throw new RuntimeException('Telegram getFile returned no file path.');
        }

        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw VoiceException::runtimeFailed();
        }

        try {
            $ok = $this->bot->downloadFile($file, $absolutePath);
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram downloadFile failed.', 0, $exception);
        }

        if ($ok !== true || ! is_file($absolutePath)) {
            throw new RuntimeException('Telegram downloadFile did not write a file.');
        }

        $bytes = filesize($absolutePath);

        if ($bytes === false || $bytes < 1) {
            throw new RuntimeException('Telegram downloadFile wrote an empty file.');
        }

        $reported = $file->file_size ?? null;

        return new TelegramDownloadedVoiceFile(
            absolutePath: $absolutePath,
            byteLength: (int) $bytes,
            reportedFileSize: is_numeric($reported) ? (int) $reported : null,
        );
    }
}
