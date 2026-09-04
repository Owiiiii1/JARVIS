<?php

namespace App\Services\Voice;

use App\Enums\VoiceSttProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class VoiceAudioMime
{
    /**
     * Browser MediaRecorder types, most specific first.
     *
     * @var list<string>
     */
    public const RECORDER_CANDIDATES = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/ogg',
        'audio/mp4',
    ];

    /**
     * @var array<string, string>
     */
    private const ALIASES = [
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/mpeg' => 'audio/mpeg',
        'audio/mp3' => 'audio/mpeg',
        'audio/x-m4a' => 'audio/mp4',
        'audio/m4a' => 'audio/mp4',
        'audio/x-aac' => 'audio/aac',
    ];

    /**
     * Official Gemini audio-understanding types plus browser recorder containers.
     *
     * @var list<string>
     */
    private const GEMINI = [
        'audio/wav',
        'audio/mpeg',
        'audio/aiff',
        'audio/aac',
        'audio/ogg',
        'audio/flac',
        'audio/webm',
        'audio/mp4',
    ];

    /**
     * @var list<string>
     */
    private const OPENAI = [
        'audio/webm',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/flac',
        'audio/aac',
        'audio/m4a',
    ];

    public static function canonicalize(string $mime): string
    {
        $base = strtolower(trim(Str::before($mime, ';')));

        if ($base === '') {
            return '';
        }

        return self::ALIASES[$base] ?? $base;
    }

    public static function extension(string $mime): string
    {
        return match (self::canonicalize($mime)) {
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/3gpp' => '3gp',
            default => 'webm',
        };
    }

    public static function dottedExtension(string $mime): string
    {
        return '.'.self::extension($mime);
    }

    public static function filename(string $mime): string
    {
        return 'utterance.'.self::extension($mime);
    }

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        $allowed = config('voice.allowed_mimes', []);

        if (! is_array($allowed)) {
            return [];
        }

        $canonical = [];

        foreach ($allowed as $mime) {
            if (! is_string($mime) || $mime === '') {
                continue;
            }

            $canonical[] = self::canonicalize($mime);
        }

        return array_values(array_unique($canonical));
    }

    public static function isAllowed(string $mime): bool
    {
        $canonical = self::canonicalize($mime);

        return $canonical !== '' && in_array($canonical, self::allowed(), true);
    }

    public static function forGemini(string $mime): ?string
    {
        $canonical = self::canonicalize($mime);

        if (! in_array($canonical, self::GEMINI, true)) {
            return null;
        }

        return match ($canonical) {
            'audio/mpeg' => 'audio/mp3',
            default => $canonical,
        };
    }

    /**
     * @return list<string>
     */
    public static function supportedForProvider(string $provider): array
    {
        $providerMimes = match (VoiceSttProvider::normalize($provider)) {
            VoiceSttProvider::Gemini => self::GEMINI,
            VoiceSttProvider::OpenAi => self::OPENAI,
            VoiceSttProvider::None => self::allowed(),
        };

        return array_values(array_intersect($providerMimes, self::allowed()));
    }

    /**
     * Full MediaRecorder type strings whose canonical MIME is accepted by the STT provider.
     *
     * @return list<string>
     */
    public static function recorderCandidatesForProvider(string $provider): array
    {
        $supported = self::supportedForProvider($provider);

        return array_values(array_filter(
            self::RECORDER_CANDIDATES,
            static fn (string $type): bool => in_array(self::canonicalize($type), $supported, true),
        ));
    }

    /**
     * @return array{canonical: string, raw: string, extension: string, filename: string, allowed: bool}
     */
    public static function resolveUploaded(UploadedFile $file, ?string $clientMime = null, ?string $rawMime = null): array
    {
        $detectedRaw = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: '');
        $raw = trim((string) ($rawMime ?: $detectedRaw));
        $detected = self::canonicalize($detectedRaw);
        $client = self::canonicalize((string) $clientMime);

        $canonical = $detected;

        if (! self::isAllowed($canonical) && self::isAllowed($client)) {
            $canonical = $client;
        }

        if (in_array($canonical, ['application/octet-stream', 'application/ogg', ''], true) && self::isAllowed($client)) {
            $canonical = $client;
        }

        return [
            'canonical' => $canonical,
            'raw' => $raw !== '' ? $raw : $detectedRaw,
            'extension' => self::extension($canonical),
            'filename' => self::filename($canonical),
            'allowed' => self::isAllowed($canonical),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspacePayload(string $sttProvider): array
    {
        return [
            'stt_provider' => $sttProvider,
            'supported_input_mimes' => self::supportedForProvider($sttProvider),
            'recorder_mime_candidates' => self::recorderCandidatesForProvider($sttProvider),
            'max_utterance_seconds' => max(1, (int) config('voice.max_utterance_seconds', 30)),
            'max_audio_chunk_bytes' => max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000)),
        ];
    }
}
