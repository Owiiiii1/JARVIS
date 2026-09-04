<?php

namespace App\Services\Integrations\Google;

use App\Services\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GoogleOAuthService
{
    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('integrations.google.redirect_uri'));

        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/integrations/google/callback';
    }

    /**
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        $scopes = config('integrations.google.scopes', ['openid', 'email', 'profile']);

        return $this->normalizeScopes(is_array($scopes) ? $scopes : []);
    }

    /**
     * @return list<string>
     */
    public function calendarScopes(): array
    {
        $scopes = config('integrations.google.calendar_scopes', [
            'https://www.googleapis.com/auth/calendar',
        ]);

        return $this->normalizeScopes(is_array($scopes) ? $scopes : []);
    }

    /**
     * @param  list<string>  $scopes
     */
    public function hasCalendarScope(array $scopes): bool
    {
        $granted = $this->normalizeScopes($scopes);

        foreach ($this->calendarScopes() as $needed) {
            if (in_array($needed, $granted, true)) {
                return true;
            }
        }

        return in_array('https://www.googleapis.com/auth/calendar', $granted, true);
    }

    /**
     * @param  list<string>  $additionalScopes
     * @return array{url: string, state: string, verifier: string}
     */
    public function buildAuthorizationUrl(bool $forceConsent, array $additionalScopes = []): array
    {
        $this->assertConfigured();

        $state = bin2hex(random_bytes(32));
        $verifier = $this->pkceVerifier();
        $challenge = $this->pkceChallenge($verifier);

        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->normalizeScopes(array_merge($this->requestedScopes(), $additionalScopes))),
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        if ($forceConsent) {
            $query['prompt'] = 'consent';
        }

        return [
            'url' => (string) config('integrations.google.auth_url').'?'.http_build_query($query),
            'state' => $state,
            'verifier' => $verifier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $this->assertConfigured();

        $response = $this->http()->asForm()->post((string) config('integrations.google.token_url'), [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]);

        if (! $response->successful()) {
            $this->failFromResponse($response, 'token_exchange_failed');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! filled($payload['access_token'] ?? null)) {
            throw new IntegrationException('token_exchange_failed', 'Google token exchange failed.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(string $refreshToken): array
    {
        $this->assertConfigured();

        $response = $this->http()->asForm()->post((string) config('integrations.google.token_url'), [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = is_string($response->json('error')) ? $response->json('error') : '';
            if ($response->status() === 400 && in_array($error, ['invalid_grant', 'invalid_request'], true)) {
                $this->logFailure('refresh', 'refresh_revoked', $response->status());
                throw new IntegrationException('refresh_revoked', 'Google refresh token is no longer valid.');
            }

            $this->failFromResponse($response, 'google_unavailable');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! filled($payload['access_token'] ?? null)) {
            throw new IntegrationException('google_unavailable', 'Google token refresh failed.');
        }

        return $payload;
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool|null, name: string|null}
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $response = $this->http()
            ->withToken($accessToken)
            ->get((string) config('integrations.google.userinfo_url'));

        if (! $response->successful()) {
            $this->failFromResponse($response, 'google_unavailable');
        }

        $payload = $response->json();
        $sub = trim((string) ($payload['sub'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));

        if ($sub === '' || $email === '') {
            throw new IntegrationException('token_exchange_failed', 'Google identity is incomplete.');
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => isset($payload['email_verified']) ? (bool) $payload['email_verified'] : null,
            'name' => isset($payload['name']) ? (string) $payload['name'] : null,
        ];
    }

    public function revokeSafely(?string $token): bool
    {
        if (! filled($token) || ! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->http()->asForm()->post((string) config('integrations.google.revoke_url'), [
                'token' => $token,
            ]);

            if ($response->successful() || $response->status() === 400) {
                return $response->successful();
            }

            $this->logFailure('revoke', 'google_unavailable', $response->status());

            return false;
        } catch (Throwable) {
            $this->logFailure('revoke', 'google_unavailable');

            return false;
        }
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '') {
                $normalized[] = $scope;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function scopeLabels(array $scopes): array
    {
        $labels = [];

        foreach ($this->normalizeScopes($scopes) as $scope) {
            $labels[] = match ($scope) {
                'openid' => 'Identity',
                'email' => 'Email identity',
                'profile' => 'Profile',
                'https://www.googleapis.com/auth/calendar' => 'Calendar',
                default => basename(parse_url($scope, PHP_URL_PATH) ?: $scope),
            };
        }

        return $labels;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new IntegrationException('configuration_missing', 'Google OAuth is not configured.');
        }
    }

    private function clientId(): string
    {
        return trim((string) config('integrations.google.client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('integrations.google.client_secret'));
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('integrations.google.timeout', 10))
            ->connectTimeout((int) config('integrations.google.connect_timeout', 5))
            ->retry(0, 0, throw: false);
    }

    private function failFromResponse(Response $response, string $code): never
    {
        $this->logFailure('http', $code, $response->status());

        $safe = $response->serverError() ? 'google_unavailable' : $code;

        throw new IntegrationException($safe, 'Google OAuth request failed.');
    }

    private function logFailure(string $action, string $code, ?int $status = null): void
    {
        Log::info('google oauth', [
            'provider' => 'google',
            'action' => $action,
            'success' => false,
            'error_code' => $code,
            'http_status' => $status,
        ]);
    }

    private function pkceVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
