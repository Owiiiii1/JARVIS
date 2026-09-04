<?php

namespace App\Services\Integrations\Google;

use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use Illuminate\Support\Facades\Session;

final class GoogleOAuthState
{
    public const SESSION_KEY = 'google_oauth_state';

    /**
     * @return array{state: string, verifier: string}
     */
    public function start(User $owner, string $state, string $verifier): array
    {
        Session::put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'user_id' => $owner->id,
            'created_at' => now()->timestamp,
        ]);

        return ['state' => $state, 'verifier' => $verifier];
    }

    /**
     * @return array{state: string, verifier: string, user_id: int}
     */
    public function consume(User $owner, ?string $state): array
    {
        $payload = Session::pull(self::SESSION_KEY);

        if (! is_array($payload)
            || ! filled($payload['state'] ?? null)
            || ! hash_equals((string) $payload['state'], (string) $state)) {
            throw new IntegrationException('oauth_invalid_state', 'Google OAuth state is invalid.');
        }

        $ttl = max(60, (int) config('integrations.google.state_ttl_seconds', 600));
        $createdAt = (int) ($payload['created_at'] ?? 0);

        if ($createdAt < now()->timestamp - $ttl) {
            throw new IntegrationException('oauth_invalid_state', 'Google OAuth state has expired.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $owner->id) {
            throw new IntegrationException('oauth_invalid_state', 'Google OAuth state does not match this session.');
        }

        $verifier = (string) ($payload['verifier'] ?? '');
        if ($verifier === '') {
            throw new IntegrationException('oauth_invalid_state', 'Google OAuth state is incomplete.');
        }

        return [
            'state' => (string) $payload['state'],
            'verifier' => $verifier,
            'user_id' => (int) $payload['user_id'],
        ];
    }

    public function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
