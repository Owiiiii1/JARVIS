<?php

namespace App\Services\Voice\Providers;

use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ElevenLabsTextToSpeechProvider implements TextToSpeechProvider
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
    ) {}

    public function name(): string
    {
        return 'elevenlabs';
    }

    public function isConfigured(): bool
    {
        return $this->settings->elevenLabsApiKey() !== '';
    }

    public function synthesize(string $text, ?string $voiceId = null): SynthesizedSpeech
    {
        $apiKey = $this->settings->elevenLabsApiKey();

        if ($apiKey === '') {
            throw VoiceException::ttsNotConfigured();
        }

        $voice = trim((string) ($voiceId ?: $this->settings->effective()->elevenLabsVoiceId ?: config('voice.elevenlabs.voice_id')));

        if ($voice === '') {
            throw VoiceException::ttsNotConfigured();
        }

        $timeout = max(2, (int) config('voice.tts_timeout_seconds', 25));
        $connect = max(1, (int) config('voice.connect_timeout_seconds', 5));
        $base = rtrim((string) config('voice.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');
        $model = (string) config('voice.elevenlabs.model_id', 'eleven_multilingual_v2');
        $format = (string) config('voice.elevenlabs.output_format', 'mp3_44100_128');
        $url = $base.'/v1/text-to-speech/'.rawurlencode($voice).'?output_format='.urlencode($format);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connect)
                ->withHeaders([
                    'xi-api-key' => $apiKey,
                    'Accept' => 'audio/mpeg',
                ])
                ->asJson()
                ->post($url, [
                    'text' => $text,
                    'model_id' => $model,
                ]);
        } catch (ConnectionException) {
            throw VoiceException::ttsFailed();
        } catch (Throwable) {
            throw VoiceException::ttsFailed();
        }

        if (! $response->successful() || $response->body() === '') {
            throw VoiceException::ttsFailed();
        }

        return new SynthesizedSpeech(
            bytes: $response->body(),
            mime: 'audio/mpeg',
            voiceId: $voice,
            sampleRate: 44100,
            durationSeconds: null,
            providerMetadata: [
                'provider' => $this->name(),
                'model' => $model,
                'format' => $format,
            ],
        );
    }
}
