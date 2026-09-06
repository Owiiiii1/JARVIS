<?php

namespace App\Services\Voice;

use App\Enums\VoiceSessionStatus;
use App\Models\VoiceSession;
use App\Services\Voice\Exceptions\VoiceException;

final class ElevenLabsRealtimeSessionToken
{
    public function issue(VoiceSession $session): string
    {
        $ttl = max(60, (int) config('voice.realtime.adapter_token_ttl_seconds', 3600));
        $expiresAt = now()->addSeconds($ttl)->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $payload = $session->public_id.'.'.$expiresAt.'.'.$nonce;
        $token = $payload.'.'.hash_hmac('sha256', $payload, $this->key());

        $meta = $session->meta();
        $meta['adapter_token_hash'] = hash('sha256', $token);
        $meta['adapter_token_expires_at'] = $expiresAt;
        $session->metadata = $meta;
        $session->save();

        return $token;
    }

    public function resolve(string $token): VoiceSession
    {
        $token = trim($token);
        $parts = explode('.', $token);

        if (count($parts) !== 4) {
            throw VoiceException::realtimeUnauthorized();
        }

        [$publicId, $expiresAt, $nonce, $signature] = $parts;

        if ($publicId === '' || $nonce === '' || $signature === '') {
            throw VoiceException::realtimeUnauthorized();
        }

        $payload = $publicId.'.'.$expiresAt.'.'.$nonce;
        $expected = hash_hmac('sha256', $payload, $this->key());

        if (strlen($expected) !== strlen($signature) || ! hash_equals($expected, $signature)) {
            throw VoiceException::realtimeUnauthorized();
        }

        if ((int) $expiresAt < now()->getTimestamp()) {
            throw VoiceException::realtimeUnauthorized();
        }

        $session = VoiceSession::query()->where('public_id', $publicId)->first();

        if ($session === null || ! $session->isRealtime()) {
            throw VoiceException::realtimeUnauthorized();
        }

        $stored = (string) ($session->meta()['adapter_token_hash'] ?? '');

        if ($stored === '' || strlen($stored) !== strlen(hash('sha256', $token)) || ! hash_equals($stored, hash('sha256', $token))) {
            throw VoiceException::realtimeUnauthorized();
        }

        $storedExpiry = (int) ($session->meta()['adapter_token_expires_at'] ?? 0);

        if ($storedExpiry !== (int) $expiresAt || $storedExpiry < now()->getTimestamp()) {
            throw VoiceException::realtimeUnauthorized();
        }

        if ($session->status === VoiceSessionStatus::Ended) {
            throw VoiceException::realtimeUnauthorized();
        }

        return $session;
    }

    private function key(): string
    {
        return (string) config('app.key');
    }
}
