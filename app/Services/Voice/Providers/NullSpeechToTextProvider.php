<?php

namespace App\Services\Voice\Providers;

use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\DTO\SpeechTranscript;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;

final class NullSpeechToTextProvider implements SpeechToTextProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript
    {
        throw VoiceException::sttNotConfigured();
    }
}
