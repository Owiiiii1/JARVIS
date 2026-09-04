<?php

namespace App\Services\Voice;

use App\Enums\VoiceSttProvider;
use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\DTO\SpeechTranscript;
use App\Services\Voice\DTO\VoiceAudioChunk;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\Providers\GeminiSpeechToTextProvider;
use App\Services\Voice\Providers\NullSpeechToTextProvider;
use App\Services\Voice\Providers\OpenAiSpeechToTextProvider;

final class SpeechToTextManager
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
        private readonly GeminiSpeechToTextProvider $gemini,
        private readonly OpenAiSpeechToTextProvider $openAi,
        private readonly NullSpeechToTextProvider $disabled,
    ) {}

    public function activeProvider(): SpeechToTextProvider
    {
        return match ($this->settings->effective()->sttProvider) {
            VoiceSttProvider::Gemini => $this->gemini,
            VoiceSttProvider::OpenAi => $this->openAi,
            VoiceSttProvider::None => $this->disabled,
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

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript
    {
        $provider = $this->activeProvider();

        if (! $provider->isConfigured()) {
            throw VoiceException::sttNotConfigured();
        }

        return $provider->transcribe($chunk, $language);
    }
}
