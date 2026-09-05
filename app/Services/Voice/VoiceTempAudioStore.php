<?php

namespace App\Services\Voice;

use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class VoiceTempAudioStore implements StoresEphemeralVoiceAudio
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

    public function putBytes(string $relativePath, string $bytes): string
    {
        $relative = $this->guardedRelative($relativePath, $this->outboundDirectory());
        $absolute = $this->diskPath($relative);
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw VoiceException::runtimeFailed();
        }

        $written = @file_put_contents($absolute, $bytes);

        if ($written === false || ! is_file($absolute) || filesize($absolute) < 1) {
            throw VoiceException::runtimeFailed();
        }

        @chmod($absolute, 0664);

        return $relative;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->diskPath($this->guardedRelative($relativePath, $this->rootFor($relativePath)));
    }

    public function deleteRelative(string $relativePath): void
    {
        $relative = $this->guardedRelative($relativePath, $this->rootFor($relativePath));
        $disk = Storage::disk((string) config('voice.audio_disk', 'local'));
        $disk->delete($relative);

        $absolute = $this->diskPath($relative);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function purgeStale(): int
    {
        $cutoff = now()->subSeconds(max(30, (int) config('voice.temp_retry_seconds', 120)))->getTimestamp();

        return $this->purgeRoot($this->tempDirectory(), $cutoff)
            + $this->purgeRoot($this->outboundDirectory(), $cutoff);
    }

    public function normalizeMime(string $mime): string
    {
        return VoiceAudioMime::canonicalize($mime);
    }

    public function mimeAllowed(string $mime): bool
    {
        return VoiceAudioMime::isAllowed($mime);
    }

    private function purgeRoot(string $root, int $cutoff): int
    {
        $disk = Storage::disk((string) config('voice.audio_disk', 'local'));
        $deleted = 0;

        try {
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
        } catch (Throwable) {
            return $this->purgeRootNative($root, $cutoff);
        }

        return $deleted;
    }

    private function purgeRootNative(string $root, int $cutoff): int
    {
        $absoluteRoot = $this->diskPath($root);

        if (! is_dir($absoluteRoot) || ! is_readable($absoluteRoot)) {
            return 0;
        }

        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getMTime() < $cutoff) {
                @unlink($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }

    private function tempDirectory(): string
    {
        return trim((string) config('voice.temp_directory', 'voice-temp'), '/');
    }

    private function outboundDirectory(): string
    {
        return trim((string) config('voice.outbound_directory', 'voice-outbound'), '/');
    }

    private function rootFor(string $relativePath): string
    {
        $relative = str_replace('\\', '/', ltrim($relativePath, '/'));
        $outbound = $this->outboundDirectory();

        return str_starts_with($relative, $outbound.'/')
            ? $outbound
            : $this->tempDirectory();
    }

    private function guardedRelative(string $relativePath, ?string $root = null): string
    {
        $root ??= $this->tempDirectory();
        $relative = str_replace('\\', '/', ltrim($relativePath, '/'));

        if (str_starts_with($relative, $root.'/')) {
            $relative = substr($relative, strlen($root) + 1);
        }

        $relative = $root.'/'.ltrim($relative, '/');

        if (str_contains($relative, '..')) {
            throw VoiceException::runtimeFailed();
        }

        return $relative;
    }

    private function diskPath(string $relative): string
    {
        return Storage::disk((string) config('voice.audio_disk', 'local'))->path($relative);
    }

    private function relativePath(string $absolute): ?string
    {
        $diskRoot = $this->diskPath('');
        $diskRoot = rtrim($diskRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($absolute, $diskRoot)) {
            return null;
        }

        return str_replace('\\', '/', substr($absolute, strlen($diskRoot)));
    }
}
