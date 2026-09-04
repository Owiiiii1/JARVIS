<?php

namespace App\Services\Voice;

use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class VoiceTempAudioStore
{
    public function store(string $sessionPublicId, int $userId, int $sequence, UploadedFile $file, bool $isFinal, ?int $sampleRate, int $channels, ?int $durationMs): VoiceAudioChunk
    {
        $bytes = (int) $file->getSize();
        $max = max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000));

        if ($bytes <= 0 || $bytes > $max) {
            throw VoiceException::audioTooLarge();
        }

        $mime = $this->normalizeMime((string) ($file->getMimeType() ?: $file->getClientMimeType()));

        if (! $this->mimeAllowed($mime)) {
            throw VoiceException::audioFormatUnsupported();
        }

        $maxSeconds = max(1, (int) config('voice.max_utterance_seconds', 30));

        if ($durationMs !== null && $durationMs > ($maxSeconds * 1000)) {
            throw VoiceException::audioTooLarge();
        }

        $path = sprintf(
            '%s/%d/%s/%s-%d%s',
            trim((string) config('voice.temp_directory', 'voice-temp'), '/'),
            $userId,
            $sessionPublicId,
            now()->format('YmdHis'),
            $sequence,
            $this->extension($mime),
        );

        $disk = (string) config('voice.audio_disk', 'local');
        Storage::disk($disk)->put($path, (string) file_get_contents($file->getRealPath()));

        return new VoiceAudioChunk(
            sessionPublicId: $sessionPublicId,
            sequence: $sequence,
            absolutePath: Storage::disk($disk)->path($path),
            byteLength: $bytes,
            mime: $mime,
            sampleRate: $sampleRate,
            channels: max(1, $channels),
            isFinal: $isFinal,
            durationMs: $durationMs,
            capturedAt: now(),
        );
    }

    public function delete(VoiceAudioChunk $chunk): void
    {
        $relative = $this->relativePath($chunk->absolutePath);

        if ($relative !== null) {
            Storage::disk((string) config('voice.audio_disk', 'local'))->delete($relative);
        }

        if (is_file($chunk->absolutePath)) {
            @unlink($chunk->absolutePath);
        }
    }

    public function purgeStale(): int
    {
        $disk = Storage::disk((string) config('voice.audio_disk', 'local'));
        $root = trim((string) config('voice.temp_directory', 'voice-temp'), '/');
        $retry = max(30, (int) config('voice.temp_retry_seconds', 120));
        $cutoff = now()->subSeconds($retry)->getTimestamp();
        $deleted = 0;

        if (! $disk->exists($root)) {
            return 0;
        }

        foreach ($disk->allFiles($root) as $path) {
            $modified = $disk->lastModified($path);

            if ($modified !== false && $modified < $cutoff) {
                $disk->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function normalizeMime(string $mime): string
    {
        $base = strtolower(trim(Str::before($mime, ';')));

        return match ($base) {
            'audio/x-m4a' => 'audio/m4a',
            'audio/wave' => 'audio/wav',
            default => $base,
        };
    }

    public function mimeAllowed(string $mime): bool
    {
        $allowed = config('voice.allowed_mimes', []);

        return in_array($this->normalizeMime($mime), is_array($allowed) ? $allowed : [], true);
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'audio/mpeg', 'audio/mp3' => '.mp3',
            'audio/wav', 'audio/x-wav' => '.wav',
            'audio/ogg' => '.ogg',
            'audio/mp4', 'audio/m4a' => '.m4a',
            'audio/flac' => '.flac',
            'audio/aac' => '.aac',
            default => '.webm',
        };
    }

    private function relativePath(string $absolute): ?string
    {
        $diskRoot = Storage::disk((string) config('voice.audio_disk', 'local'))->path('');
        $diskRoot = rtrim($diskRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($absolute, $diskRoot)) {
            return null;
        }

        return str_replace('\\', '/', substr($absolute, strlen($diskRoot)));
    }
}
