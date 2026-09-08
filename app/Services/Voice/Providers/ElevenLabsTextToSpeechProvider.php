<?php

namespace App\Services\Voice\Providers;

use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\DTO\TextToSpeechOptions;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function synthesize(string $text, ?string $voiceId = null, ?TextToSpeechOptions $options = null): SynthesizedSpeech
    {
        $apiKey = $this->settings->elevenLabsApiKey();

        if ($apiKey === '') {
            throw VoiceException::ttsNotConfigured();
        }

        $preferred = $this->resolveVoiceId($voiceId);
        $fallback = $this->fallbackVoiceId($preferred);

        try {
            return $this->requestSpeech($text, $preferred, $apiKey, $options);
        } catch (VoiceException $exception) {
            if (! $this->shouldFallbackVoice($exception, $fallback)) {
                throw $exception;
            }

            Log::info('voice.tts.voice_fallback', [
                'from_voice' => $preferred,
                'to_voice' => $fallback,
                'http_status' => $exception->context['http_status'] ?? null,
            ]);

            return $this->requestSpeech($text, $fallback, $apiKey, $options);
        }
    }

    private function resolveVoiceId(?string $voiceId): string
    {
        $voice = trim((string) ($voiceId ?: $this->settings->effective()->elevenLabsVoiceId ?: config('voice.elevenlabs.voice_id')));

        if ($voice === '') {
            throw VoiceException::ttsNotConfigured();
        }

        return $voice;
    }

    private function fallbackVoiceId(string $preferred): string
    {
        $fallback = trim((string) ($this->settings->effective()->elevenLabsVoiceId ?: config('voice.elevenlabs.voice_id')));

        if ($fallback === '' || $fallback === $preferred) {
            return '';
        }

        return $fallback;
    }

    private function requestSpeech(string $text, string $voice, string $apiKey, ?TextToSpeechOptions $options): SynthesizedSpeech
    {
        $timeout = max(2, (int) config('voice.tts_timeout_seconds', 25));
        $connect = max(1, (int) config('voice.connect_timeout_seconds', 5));
        $base = rtrim((string) config('voice.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');
        $model = (string) config('voice.elevenlabs.model_id', 'eleven_multilingual_v2');
        $format = (string) config('voice.elevenlabs.output_format', 'mp3_44100_128');
        $url = $base.'/v1/text-to-speech/'.rawurlencode($voice).'?output_format='.urlencode($format);
        $payload = $this->requestPayload($text, $model, $options);
        $speed = isset($payload['voice_settings']['speed']) ? (float) $payload['voice_settings']['speed'] : null;

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connect)
                ->withHeaders([
                    'xi-api-key' => $apiKey,
                    'Accept' => 'audio/mpeg',
                ])
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw VoiceException::ttsFailed(['reason' => 'connection']);
        } catch (Throwable) {
            throw VoiceException::ttsFailed(['reason' => 'transport']);
        }

        if (! $response->successful() || $response->body() === '') {
            throw $this->httpFailure($response, $voice);
        }

        $metadata = [
            'provider' => $this->name(),
            'model' => $model,
            'format' => $format,
        ];

        if ($speed !== null) {
            $metadata['speed'] = $speed;
        }

        return new SynthesizedSpeech(
            bytes: $response->body(),
            mime: 'audio/mpeg',
            voiceId: $voice,
            sampleRate: 44100,
            durationSeconds: null,
            providerMetadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(string $text, string $model, ?TextToSpeechOptions $options): array
    {
        $payload = [
            'text' => $text,
            'model_id' => $model,
        ];

        $voiceSettings = $this->voiceSettingsPayload($options);

        if ($voiceSettings !== []) {
            $payload['voice_settings'] = $voiceSettings;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function voiceSettingsPayload(?TextToSpeechOptions $options): array
    {
        if ($options === null) {
            return [];
        }

        $settings = $options->voiceSettings;
        $speed = $this->normalizedSpeed($options->speed ?? ($settings['speed'] ?? null));

        if ($speed !== null) {
            $settings['speed'] = $speed;
        } else {
            unset($settings['speed']);
        }

        return $settings;
    }

    private function normalizedSpeed(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $speed = (float) $value;

        if (! is_finite($speed)) {
            return null;
        }

        $min = 0.70;
        $max = 1.20;

        return round(min($max, max($min, $speed)), 2);
    }

    private function httpFailure(Response $response, string $voice): VoiceException
    {
        return VoiceException::ttsFailed([
            'reason' => $response->body() === '' ? 'empty_body' : 'http',
            'http_status' => $response->status(),
            'voice_id' => $voice,
            'voice_unavailable' => $this->isVoiceUnavailable($response),
        ]);
    }

    private function shouldFallbackVoice(VoiceException $exception, string $fallback): bool
    {
        if ($exception->error !== 'voice_tts_failed' || $fallback === '') {
            return false;
        }

        $reason = (string) ($exception->context['reason'] ?? '');

        if (in_array($reason, ['connection', 'transport'], true)) {
            return false;
        }

        $status = (int) ($exception->context['http_status'] ?? 0);

        if (in_array($status, [401, 402, 403, 408, 429, 500, 502, 503, 504], true)) {
            return false;
        }

        return (bool) ($exception->context['voice_unavailable'] ?? false);
    }

    private function isVoiceUnavailable(Response $response): bool
    {
        $status = $response->status();

        if (in_array($status, [401, 402, 403, 408, 429, 500, 502, 503, 504], true)) {
            return false;
        }

        if ($status === 404) {
            return true;
        }

        $detail = $this->providerErrorText($response);

        return str_contains($detail, 'invalid_voice')
            || str_contains($detail, 'voice_not_found')
            || str_contains($detail, 'library voice')
            || str_contains($detail, 'unknown_voice')
            || str_contains($detail, 'voice does not exist');
    }

    private function providerErrorText(Response $response): string
    {
        $json = $response->json();
        $detail = is_array($json) ? ($json['detail'] ?? $json['message'] ?? '') : '';

        if (is_array($detail)) {
            $parts = [];

            foreach (['status', 'message', 'code'] as $key) {
                if (isset($detail[$key]) && is_string($detail[$key])) {
                    $parts[] = $detail[$key];
                }
            }

            $detail = implode(' ', $parts);
        }

        return is_string($detail) ? strtolower($detail) : '';
    }
}
