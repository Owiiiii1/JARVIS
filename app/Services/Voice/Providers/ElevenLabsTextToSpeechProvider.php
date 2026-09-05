<?php

namespace App\Services\Voice\Providers;

use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\DTO\SynthesizedSpeech;
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

    public function synthesize(string $text, ?string $voiceId = null): SynthesizedSpeech
    {
        $apiKey = $this->settings->elevenLabsApiKey();

        if ($apiKey === '') {
            throw VoiceException::ttsNotConfigured();
        }

        $preferred = $this->resolveVoiceId($voiceId);
        $fallback = $this->fallbackVoiceId($preferred);

        try {
            return $this->requestSpeech($text, $preferred, $apiKey);
        } catch (VoiceException $exception) {
            if (! $this->shouldFallbackVoice($exception, $fallback)) {
                throw $exception;
            }

            Log::info('voice.tts.voice_fallback', [
                'from_voice' => $preferred,
                'to_voice' => $fallback,
                'http_status' => $exception->context['http_status'] ?? null,
            ]);

            return $this->requestSpeech($text, $fallback, $apiKey);
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

    private function requestSpeech(string $text, string $voice, string $apiKey): SynthesizedSpeech
    {
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
            throw VoiceException::ttsFailed(['reason' => 'connection']);
        } catch (Throwable) {
            throw VoiceException::ttsFailed(['reason' => 'transport']);
        }

        if (! $response->successful() || $response->body() === '') {
            throw $this->httpFailure($response, $voice);
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
