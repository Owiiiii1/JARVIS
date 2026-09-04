<?php

namespace App\Services\Voice\Exceptions;

use RuntimeException;

final class VoiceException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $error,
        string $message = '',
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }

    public static function forbidden(): self
    {
        return new self('voice_forbidden', 'Voice is not available for this account.', 403);
    }

    public static function notFound(): self
    {
        return new self('voice_session_not_found', 'Voice session was not found.', 404);
    }

    public static function invalidState(string $from, string $to): self
    {
        return new self(
            'voice_session_invalid_state',
            'This voice action is not allowed in the current session state.',
            409,
            ['from' => $from, 'to' => $to],
        );
    }

    public static function limitReached(): self
    {
        return new self('voice_session_limit_reached', 'Too many active voice sessions.');
    }

    public static function expired(): self
    {
        return new self('voice_session_expired', 'This voice session has expired.', 410);
    }

    public static function audioTooLarge(): self
    {
        return new self('voice_audio_too_large', 'Audio chunk exceeds the allowed size.');
    }

    public static function audioFormatUnsupported(): self
    {
        return new self('voice_audio_format_unsupported', 'This audio format is not supported.');
    }

    public static function sttNotConfigured(): self
    {
        return new self('voice_stt_not_configured', 'Speech-to-text is not configured.');
    }

    public static function sttFailed(): self
    {
        return new self('voice_stt_failed', 'Speech-to-text failed.');
    }

    public static function sttRateLimited(): self
    {
        return new self('voice_stt_rate_limited', 'Speech-to-text is rate limited.', 429);
    }

    public static function sttTimeout(): self
    {
        return new self('voice_stt_timeout', 'Speech-to-text timed out.', 504);
    }

    public static function ttsNotConfigured(): self
    {
        return new self('voice_tts_not_configured', 'Text-to-speech is not configured.');
    }

    public static function ttsFailed(): self
    {
        return new self('voice_tts_failed', 'Text-to-speech failed.');
    }

    public static function runtimeFailed(): self
    {
        return new self('voice_runtime_failed', 'Voice runtime failed.', 500);
    }
}
