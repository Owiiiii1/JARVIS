<?php

namespace Tests\Unit\Voice;

use App\Services\Ai\GeminiCredentialResolver;
use App\Services\Voice\DTO\TextToSpeechOptions;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\Providers\ElevenLabsTextToSpeechProvider;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ElevenLabsTextToSpeechProviderTest extends VoiceProviderTestCase
{
    private const USER_VOICE = 'user-selected-voice';

    private const FALLBACK_VOICE = 'instance-fallback-voice';

    private const API_KEY = 'test-elevenlabs-key-do-not-log';

    public function test_selected_valid_voice_sends_one_request(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response('mp3-bytes', 200),
        ]);

        $speech = $this->provider()->synthesize('Hello there.', self::USER_VOICE);

        $this->assertSame('mp3-bytes', $speech->bytes);
        $this->assertSame(self::USER_VOICE, $speech->voiceId);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/'.self::USER_VOICE)
            && $request->hasHeader('xi-api-key', self::API_KEY)
            && ($request['text'] ?? null) === 'Hello there.'
            && ! isset($request['voice_settings']));
    }

    public function test_unavailable_selected_voice_falls_back_once_to_instance_voice(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response(['detail' => 'invalid_voice_id'], 404),
            'api.elevenlabs.io/v1/text-to-speech/'.self::FALLBACK_VOICE.'*' => Http::response('fallback-mp3', 200),
        ]);

        $speech = $this->provider()->synthesize('Hello there.', self::USER_VOICE);

        $this->assertSame('fallback-mp3', $speech->bytes);
        $this->assertSame(self::FALLBACK_VOICE, $speech->voiceId);
        Http::assertSentCount(2);
        Http::assertSentInOrder([
            fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/'.self::USER_VOICE),
            fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/'.self::FALLBACK_VOICE),
        ]);
    }

    public function test_auth_failure_does_not_fall_back(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'invalid api key'], 401),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', self::USER_VOICE);
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame('voice_tts_failed', $exception->error);
            $this->assertSame(401, $exception->context['http_status']);
            $this->assertSame(self::USER_VOICE, $exception->context['voice_id']);
            $this->assertArrayNotHasKey('detail', $exception->context);
            $this->assertFalse($exception->context['voice_unavailable']);
        }

        Http::assertSentCount(1);
    }

    public function test_quota_failure_does_not_fall_back(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'quota exceeded'], 402),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', self::USER_VOICE);
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame(402, $exception->context['http_status']);
            $this->assertFalse($exception->context['voice_unavailable']);
        }

        Http::assertSentCount(1);
    }

    public function test_rate_limit_does_not_fall_back(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'too many requests'], 429),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', self::USER_VOICE);
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame(429, $exception->context['http_status']);
            $this->assertFalse($exception->context['voice_unavailable']);
        }

        Http::assertSentCount(1);
    }

    public function test_generic_provider_failure_does_not_fall_back(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'internal error'], 500),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', self::USER_VOICE);
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame(500, $exception->context['http_status']);
            $this->assertFalse($exception->context['voice_unavailable']);
        }

        Http::assertSentCount(1);
    }

    public function test_fallback_failure_is_surfaced_once(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response(['detail' => 'voice_not_found'], 404),
            'api.elevenlabs.io/v1/text-to-speech/'.self::FALLBACK_VOICE.'*' => Http::response(['detail' => 'voice_not_found'], 404),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', self::USER_VOICE);
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame('voice_tts_failed', $exception->error);
            $this->assertSame(self::FALLBACK_VOICE, $exception->context['voice_id']);
            $this->assertArrayNotHasKey('detail', $exception->context);
        }

        Http::assertSentCount(2);
    }

    public function test_requested_user_voice_is_used_before_instance_fallback(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response('user-mp3', 200),
            'api.elevenlabs.io/v1/text-to-speech/'.self::FALLBACK_VOICE.'*' => Http::response('fallback-mp3', 200),
        ]);

        $speech = $this->provider()->synthesize('Hello there.', self::USER_VOICE);

        $this->assertSame(self::USER_VOICE, $speech->voiceId);
        $this->assertSame('user-mp3', $speech->bytes);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/'.self::FALLBACK_VOICE));
    }

    public function test_speed_option_is_sent_inside_voice_settings(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response('mp3-bytes', 200),
        ]);

        $speech = $this->provider()->synthesize(
            'Hello there.',
            self::USER_VOICE,
            TextToSpeechOptions::withSpeed(1.15),
        );

        $this->assertSame(1.15, $speech->providerMetadata['speed'] ?? null);
        Http::assertSent(fn (Request $request): bool => ($request['voice_settings']['speed'] ?? null) === 1.15
            && ($request['text'] ?? null) === 'Hello there.'
            && ($request['model_id'] ?? null) !== null);
    }

    public function test_fallback_voice_keeps_the_same_speed(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response(['detail' => 'invalid_voice_id'], 404),
            'api.elevenlabs.io/v1/text-to-speech/'.self::FALLBACK_VOICE.'*' => Http::response('fallback-mp3', 200),
        ]);

        $this->provider()->synthesize(
            'Hello there.',
            self::USER_VOICE,
            TextToSpeechOptions::withSpeed(1.15),
        );

        Http::assertSentCount(2);
        Http::assertSentInOrder([
            fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/'.self::USER_VOICE)
                && ($request['voice_settings']['speed'] ?? null) === 1.15,
            fn (Request $request): bool => str_contains($request->url(), '/text-to-speech/'.self::FALLBACK_VOICE)
                && ($request['voice_settings']['speed'] ?? null) === 1.15,
        ]);
    }

    public function test_out_of_range_speed_is_clamped_before_elevenlabs(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response('mp3-bytes', 200),
        ]);

        $this->provider()->synthesize(
            'Hello there.',
            self::USER_VOICE,
            TextToSpeechOptions::withSpeed(1.90),
        );

        Http::assertSent(fn (Request $request): bool => ($request['voice_settings']['speed'] ?? null) === 1.2);
    }

    public function test_speed_merges_into_existing_voice_settings(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/'.self::USER_VOICE.'*' => Http::response('mp3-bytes', 200),
        ]);

        $this->provider()->synthesize(
            'Hello there.',
            self::USER_VOICE,
            new TextToSpeechOptions(
                speed: 1.15,
                voiceSettings: [
                    'stability' => 0.5,
                    'similarity_boost' => 0.75,
                ],
            ),
        );

        Http::assertSent(fn (Request $request): bool => ($request['voice_settings']['speed'] ?? null) === 1.15
            && ($request['voice_settings']['stability'] ?? null) === 0.5
            && ($request['voice_settings']['similarity_boost'] ?? null) === 0.75);
    }

    public function test_connection_failure_does_not_fall_back(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::failedConnection(),
        ]);

        try {
            $this->provider()->synthesize('Hello there.', self::USER_VOICE);
            $this->fail('Expected VoiceException.');
        } catch (VoiceException $exception) {
            $this->assertSame('connection', $exception->context['reason']);
        }

        Http::assertSentCount(1);
    }

    private function provider(): ElevenLabsTextToSpeechProvider
    {
        config([
            'voice.elevenlabs.api_key' => self::API_KEY,
            'voice.elevenlabs.voice_id' => self::FALLBACK_VOICE,
            'voice.elevenlabs.base_url' => 'https://api.elevenlabs.io',
        ]);

        return new ElevenLabsTextToSpeechProvider(
            new VoiceSettingsService(new GeminiCredentialResolver),
        );
    }
}
