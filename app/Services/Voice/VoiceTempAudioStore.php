<?php

namespace App\Services\Voice;

use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class VoiceTempAudioStore
{
    public function store(
        string $sessionPublicId,
        int $userId,
        int $sequence,
        UploadedFile $file,
        bool $isFinal,
        ?int $sampleRate,
        int $channels,
        ?int $durationMs,
        ?string $clientMime = null,
        ?string $rawMime = null,
    ): VoiceAudioChunk {
        $bytes = (int) $file->getSize();
        $max = max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000));

        if ($bytes <= 0 || $bytes > $max) {
            throw VoiceException::audioTooLarge();
        }

        $resolved = VoiceAudioMime::resolveUploaded($file, $clientMime, $rawMime);
        $mime = $resolved['canonical'];

        if (! $resolved['allowed']) {
            throw VoiceException::audioFormatUnsupported([
                'raw_mime' => $resolved['raw'],
                'canonical_mime' => $mime,
                'extension' => $resolved['extension'],
                'audio_bytes' => $bytes,
            ]);
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
            VoiceAudioMime::dottedExtension($mime),
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
        return VoiceAudioMime::canonicalize($mime);
    }

    public function mimeAllowed(string $mime): bool
    {
        return VoiceAudioMime::isAllowed($mime);
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
