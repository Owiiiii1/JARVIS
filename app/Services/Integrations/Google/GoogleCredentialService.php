<?php

namespace App\Services\Integrations\Google;

use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class GoogleCredentialService
{
    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleOAuthService $oauth,
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

            return $this->refresh($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function isExpired(array $envelope): bool
    {
        $expiresAt = $envelope['expires_at'] ?? null;
        if (! is_string($expiresAt) || $expiresAt === '') {
            return true;
        }

        $skew = max(0, (int) config('integrations.google.refresh_skew_seconds', 120));

        return Carbon::parse($expiresAt)->lte(now()->addSeconds($skew));
    }

    public function refresh(IntegrationAccount $account): string
    {
        $envelope = $this->accounts->getCredentials($account);
        $refreshToken = (string) ($envelope['refresh_token'] ?? '');

        if ($refreshToken === '') {
            $this->accounts->markError($account, 'refresh_revoked');
            throw new IntegrationException('refresh_revoked', 'Google refresh token is missing.');
        }

        try {
            $response = $this->oauth->refreshToken($refreshToken);
        } catch (IntegrationException $exception) {
            if ($exception->error === 'refresh_revoked') {
                $this->accounts->markRevoked($account);
                $account->forceFill(['last_error_code' => 'refresh_revoked', 'last_error_at' => now()])->save();
            } else {
                $this->accounts->markError($account, $exception->error);
            }

            throw $exception;
        }

        $merged = $this->mergeTokenResponse($envelope, $response);
        $this->accounts->setCredentials($account, $merged);
        $this->accounts->recordAuthSuccess($account->fresh() ?? $account);

        Log::info('google oauth', [
            'provider' => 'google',
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

        return [
            'access_token' => $incoming['access_token'] ?? $existing['access_token'] ?? null,
            'refresh_token' => $refresh,
            'token_type' => $incoming['token_type'] ?? $existing['token_type'] ?? 'Bearer',
            'expires_at' => $this->expiresAtFromResponse($incoming) ?? ($existing['expires_at'] ?? null),
        ];
    }

    /**
     * Local only. Does not call Google.
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

        if ($hasRefresh || ($hasAccess && ! $this->isExpired($envelope))) {
            return 'healthy';
        }

        return 'needs_reconnect';
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
