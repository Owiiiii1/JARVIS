<?php

namespace App\Services\Voice\Providers;

use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\DTO\TextToSpeechOptions;
use App\Services\Voice\Exceptions\VoiceException;

final class NullTextToSpeechProvider implements TextToSpeechProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function synthesize(string $text, ?string $voiceId = null, ?TextToSpeechOptions $options = null): SynthesizedSpeech
    {
        throw VoiceException::ttsNotConfigured();
    }
}
