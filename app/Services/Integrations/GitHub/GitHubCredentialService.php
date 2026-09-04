<?php

namespace App\Services\Integrations\GitHub;

use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GitHubCredentialService
{
    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly GitHubOAuthService $oauth,
    ) {}

    public function getValidAccessToken(IntegrationAccount $account): string
    {
        return DB::transaction(function () use ($account): string {
            /** @var IntegrationAccount $locked */
            $locked = IntegrationAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->firstOrFail();

            $envelope = $this->accounts->getCredentials($locked);
            $access = (string) ($envelope['access_token'] ?? '');

            if ($access !== '' && ! $this->isExpired($envelope)) {
                return $access;
            }

            if ($access !== '' && ! filled($envelope['refresh_token'] ?? null) && ! $this->hasExpiry($envelope)) {
                return $access;
            }

            return $this->refresh($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function isExpired(array $envelope): bool
    {
        if (! $this->hasExpiry($envelope)) {
            return false;
        }

        $skew = max(0, (int) config('integrations.github.refresh_skew_seconds', 120));

        return Carbon::parse((string) $envelope['expires_at'])->lte(now()->addSeconds($skew));
    }

    public function refresh(IntegrationAccount $account): string
    {
        $envelope = $this->accounts->getCredentials($account);
        $refreshToken = (string) ($envelope['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $this->accounts->markError($account, 'github_token_revoked');
            throw new IntegrationException('github_token_revoked', 'GitHub access token is missing or expired.');
        }

        try {
            $response = $this->oauth->refreshToken($refreshToken);
        } catch (IntegrationException $exception) {
            if ($exception->error === 'github_token_revoked') {
                $this->accounts->markRevoked($account);
                $account->forceFill(['last_error_code' => 'github_token_revoked', 'last_error_at' => now()])->save();
            } else {
                $this->accounts->markError($account, $exception->error);
            }

            throw $exception;
        }

        $merged = $this->mergeTokenResponse($envelope, $response);
        $this->accounts->setCredentials($account, $merged);
        $this->accounts->recordAuthSuccess($account->fresh() ?? $account);

        Log::info('github oauth', [
            'provider' => 'github',
            'action' => 'refresh',
            'account_id' => $account->id,
            'success' => true,
        ]);

        return (string) $merged['access_token'];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function mergeTokenResponse(array $existing, array $incoming): array
    {
        $refresh = $incoming['refresh_token'] ?? null;
        if (! filled($refresh)) {
            $refresh = $existing['refresh_token'] ?? null;
        }

        $scope = $incoming['scope'] ?? ($existing['scope'] ?? []);
        if (is_string($scope)) {
            $scope = $this->oauth->normalizeScopes([$scope]);
        } elseif (is_array($scope)) {
            $scope = $this->oauth->normalizeScopes($scope);
        } else {
            $scope = [];
        }

        return [
            'access_token' => $incoming['access_token'] ?? $existing['access_token'] ?? null,
            'refresh_token' => filled($refresh) ? $refresh : null,
            'token_type' => strtolower((string) ($incoming['token_type'] ?? $existing['token_type'] ?? 'bearer')),
            'scope' => $scope,
            'expires_at' => $this->expiresAtFromResponse($incoming) ?? ($existing['expires_at'] ?? null),
        ];
    }

    /**
     * Local only. Does not call GitHub.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function healthFromEnvelope(array $envelope): string
    {
        $hasRefresh = filled($envelope['refresh_token'] ?? null);
        $hasAccess = filled($envelope['access_token'] ?? null);

        if (! $hasRefresh && ! $hasAccess) {
            return 'missing';
        }

        if ($hasRefresh && $this->isExpired($envelope)) {
            return 'refreshable';
        }

        if ($hasAccess && ! $this->isExpired($envelope)) {
            return 'healthy';
        }

        if ($hasRefresh) {
            return 'refreshable';
        }

        return 'needs_reconnect';
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hasExpiry(array $envelope): bool
    {
        $expiresAt = $envelope['expires_at'] ?? null;

        return is_string($expiresAt) && $expiresAt !== '';
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    private function expiresAtFromResponse(array $incoming): ?string
    {
        if (isset($incoming['expires_at']) && is_string($incoming['expires_at']) && $incoming['expires_at'] !== '') {
            return Carbon::parse($incoming['expires_at'])->toIso8601String();
        }

        if (isset($incoming['expires_in']) && is_numeric($incoming['expires_in'])) {
            return now()->addSeconds(max(0, (int) $incoming['expires_in']))->toIso8601String();
        }

        return null;
    }
}
