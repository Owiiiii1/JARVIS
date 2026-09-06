<?php

namespace App\Services\Voice;

use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ElevenLabsRealtimeClient
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
    ) {}

    public function signedConversationUrl(string $agentId): string
    {
        $apiKey = $this->settings->elevenLabsApiKey();
        $agentId = trim($agentId);

        if ($apiKey === '' || $agentId === '') {
            throw VoiceException::realtimeNotConfigured();
        }

        $base = rtrim((string) config('voice.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');
        $path = (string) config('voice.realtime.signed_url_path', '/v1/convai/conversation/get-signed-url');
        $timeout = max(2, (int) config('voice.tts_timeout_seconds', 25));
        $connect = max(1, (int) config('voice.connect_timeout_seconds', 5));

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connect)
                ->withHeaders([
                    'xi-api-key' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($base.$path, [
                    'agent_id' => $agentId,
                ]);
        } catch (ConnectionException) {
            throw VoiceException::realtimeUnavailable();
        } catch (Throwable) {
            throw VoiceException::realtimeUnavailable();
        }

        $signed = trim((string) $response->json('signed_url'));

        if (! $response->successful() || $signed === '' || ! str_starts_with($signed, 'https://')) {
            throw VoiceException::realtimeUnavailable();
        }

        return $signed;
    }
}
