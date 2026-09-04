<?php

namespace App\Services\Voice;

use App\Enums\VoiceTtsProvider;
use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\Providers\ElevenLabsTextToSpeechProvider;
use App\Services\Voice\Providers\NullTextToSpeechProvider;

final class TextToSpeechManager
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly ElevenLabsTextToSpeechProvider $elevenLabs,
        private readonly NullTextToSpeechProvider $disabled,
    ) {}

    public function activeProvider(): TextToSpeechProvider
    {
        return match ($this->settings->effective()->ttsProvider) {
            VoiceTtsProvider::ElevenLabs => $this->elevenLabs,
            VoiceTtsProvider::None => $this->disabled,
        };
    }

    public function isConfigured(): bool
    {
        return $this->activeProvider()->isConfigured();
    }

    public function providerName(): string
    {
        return $this->activeProvider()->name();
    }

    public function synthesize(string $text, ?string $voiceId = null): SynthesizedSpeech
    {
        $provider = $this->activeProvider();

        if (! $provider->isConfigured()) {
            throw VoiceException::ttsNotConfigured();
        }

        return $provider->synthesize($text, $voiceId);
    }
}
