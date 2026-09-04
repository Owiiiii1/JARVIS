<?php

namespace App\Services\Integrations\GitHub;

use App\Services\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GitHubOAuthService
{
    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('integrations.github.redirect_uri'));

        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/integrations/github/callback';
    }

    /**
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        $scopes = config('integrations.github.scopes', ['repo', 'read:org']);

        return $this->normalizeScopes(is_array($scopes) ? $scopes : []);
    }

    /**
     * @return array{url: string, state: string, verifier: string}
     */
    public function buildAuthorizationUrl(): array
    {
        $this->assertConfigured();

        $state = bin2hex(random_bytes(32));
        $verifier = $this->pkceVerifier();
        $challenge = $this->pkceChallenge($verifier);

        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->requestedScopes()),
            'state' => $state,
            'allow_signup' => 'false',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return [
            'url' => (string) config('integrations.github.auth_url').'?'.http_build_query($query),
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

        $response = $this->http()->asForm()->acceptJson()->post((string) config('integrations.github.token_url'), [
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
            $error = is_string($payload['error'] ?? null) ? (string) $payload['error'] : '';
            if ($error === 'bad_verification_code' || $error === 'incorrect_client_credentials') {
                throw new IntegrationException('token_exchange_failed', 'GitHub token exchange failed.');
            }

            throw new IntegrationException('token_exchange_failed', 'GitHub token exchange failed.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(string $refreshToken): array
    {
        $this->assertConfigured();

        $response = $this->http()->asForm()->acceptJson()->post((string) config('integrations.github.token_url'), [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = is_string($response->json('error')) ? (string) $response->json('error') : '';
            if (in_array($error, ['bad_refresh_token', 'incorrect_client_credentials'], true)) {
                $this->logFailure('refresh', 'github_token_revoked', $response->status());
                throw new IntegrationException('github_token_revoked', 'GitHub refresh token is no longer valid.');
            }

            $this->failFromResponse($response, 'github_unavailable');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! filled($payload['access_token'] ?? null)) {
            throw new IntegrationException('github_unavailable', 'GitHub token refresh failed.');
        }

        return $payload;
    }

    /**
     * @return array{id: string, login: string, name: string|null, email: string|null, avatar_url: string|null, html_url: string|null}
     */
    public function fetchAuthenticatedUser(string $accessToken): array
    {
        $response = $this->apiHttp()
            ->withToken($accessToken)
            ->get(rtrim((string) config('integrations.github.api_base_url'), '/').'/user');

        if (! $response->successful()) {
            $this->failFromResponse($response, 'github_unavailable');
        }

        $payload = $response->json();
        $id = trim((string) ($payload['id'] ?? ''));
        $login = trim((string) ($payload['login'] ?? ''));

        if ($id === '' || $login === '') {
            throw new IntegrationException('token_exchange_failed', 'GitHub identity is incomplete.');
        }

        $email = trim((string) ($payload['email'] ?? ''));

        return [
            'id' => $id,
            'login' => $login,
            'name' => isset($payload['name']) && is_string($payload['name']) && $payload['name'] !== ''
                ? (string) $payload['name']
                : null,
            'email' => $email !== '' ? $email : null,
            'avatar_url' => isset($payload['avatar_url']) ? (string) $payload['avatar_url'] : null,
            'html_url' => isset($payload['html_url']) ? (string) $payload['html_url'] : null,
        ];
    }

    public function revokeSafely(?string $token): bool
    {
        if (! filled($token) || ! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->http()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->acceptJson()
                ->asJson()
                ->delete(rtrim((string) config('integrations.github.api_base_url'), '/').'/applications/'.$this->clientId().'/token', [
                    'access_token' => $token,
                ]);

            if ($response->successful() || in_array($response->status(), [204, 404], true)) {
                return $response->successful() || $response->status() === 204;
            }

            $this->logFailure('revoke', 'github_unavailable', $response->status());

            return false;
        } catch (Throwable) {
            $this->logFailure('revoke', 'github_unavailable');

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
            foreach (preg_split('/[,\s]+/', (string) $scope) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $normalized[] = $part;
                }
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
                'repo' => 'Private repositories',
                'read:org' => 'Read org membership',
                default => $scope,
            };
        }

        return $labels;
    }

    public function hasRepoScope(array $scopes): bool
    {
        return in_array('repo', $this->normalizeScopes($scopes), true);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new IntegrationException('configuration_missing', 'GitHub OAuth is not configured.');
        }
    }

    private function clientId(): string
    {
        return trim((string) config('integrations.github.client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('integrations.github.client_secret'));
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('integrations.github.timeout', 10))
            ->connectTimeout((int) config('integrations.github.connect_timeout', 5))
            ->retry(0, 0, throw: false);
    }

    private function apiHttp(): PendingRequest
    {
        return $this->http()
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => (string) config('integrations.github.api_version', '2022-11-28'),
                'User-Agent' => (string) config('integrations.github.user_agent', 'Jarvis-OwlSolutions'),
            ]);
    }

    private function failFromResponse(Response $response, string $code): never
    {
        $this->logFailure('http', $code, $response->status());

        $safe = $response->serverError() ? 'github_unavailable' : $code;

        throw new IntegrationException($safe, 'GitHub OAuth request failed.');
    }

    private function logFailure(string $action, string $code, ?int $status = null): void
    {
        Log::info('github oauth', [
            'provider' => 'github',
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
