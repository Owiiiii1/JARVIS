<?php

namespace App\Services\Integrations\GitHub;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use Illuminate\Support\Facades\Log;

final class GitHubConnectionService
{
    public function __construct(
        private readonly GitHubOAuthService $oauth,
        private readonly GitHubOAuthState $state,
        private readonly GitHubCredentialService $credentials,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function authorizationUrl(User $owner): string
    {
        $authorization = $this->oauth->buildAuthorizationUrl();
        $this->state->start($owner, $authorization['state'], $authorization['verifier']);

        Log::info('github oauth', [
            'provider' => 'github',
            'action' => 'connect',
            'success' => true,
        ]);

        return $authorization['url'];
    }

    public function complete(User $owner, ?string $state, ?string $code, ?string $oauthError): IntegrationAccount
    {
        if (filled($oauthError)) {
            $this->state->clear();
            $code = $oauthError === 'access_denied' ? 'oauth_access_denied' : 'token_exchange_failed';
            throw new IntegrationException($code, 'GitHub authorization was not completed.');
        }

        $consumed = $this->state->consume($owner, $state);

        if (! filled($code)) {
            throw new IntegrationException('token_exchange_failed', 'GitHub authorization code is missing.');
        }

        $startedAt = microtime(true);
        $tokenResponse = $this->oauth->exchangeCode((string) $code, $consumed['verifier']);
        $identity = $this->oauth->fetchAuthenticatedUser((string) $tokenResponse['access_token']);
        $scopes = $this->mergeGrantedScopes($owner, $identity['id'], $this->grantedScopes($tokenResponse));

        if (! $this->oauth->hasRepoScope($scopes)) {
            throw new IntegrationException('github_scope_required', 'GitHub did not grant repository access.');
        }

        $account = $this->persistAccount($owner, $identity, $scopes, $tokenResponse);

        Log::info('github oauth', [
            'provider' => 'github',
            'action' => 'callback',
            'account_id' => $account->id,
            'success' => true,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $account;
    }

    public function disconnect(User $owner, IntegrationAccount $account): bool
    {
        if ((int) $account->user_id !== (int) $owner->id || $account->provider !== 'github') {
            throw new IntegrationException('forbidden', 'GitHub account does not belong to this owner.');
        }

        $revokedRemotely = $this->oauth->revokeSafely($this->revokeToken($account));
        $this->accounts->disconnect($account, notifyProvider: false);

        Log::info('github oauth', [
            'provider' => 'github',
            'action' => 'disconnect',
            'account_id' => $account->id,
            'success' => true,
            'error_code' => $revokedRemotely ? null : 'github_unavailable',
        ]);

        return $revokedRemotely;
    }

    /**
     * @param  array{id: string, login: string, email: string|null, name: string|null, avatar_url: string|null, html_url: string|null}  $identity
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $tokenResponse
     */
    private function persistAccount(User $owner, array $identity, array $scopes, array $tokenResponse): IntegrationAccount
    {
        $this->deactivateOtherAccounts($owner, $identity['id']);

        $existing = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'github')
            ->where('external_account_id', $identity['id'])
            ->first();

        $merged = $this->credentials->mergeTokenResponse(
            $existing !== null ? $this->accounts->getCredentials($existing) : [],
            $tokenResponse,
        );

        $account = $this->accounts->upsertAccount(
            $owner,
            'github',
            $identity['id'],
            $identity['email'],
            IntegrationAccountStatus::Connected,
            $scopes,
            [
                'login' => $identity['login'],
                'name' => $identity['name'],
                'avatar_url' => $identity['avatar_url'],
                'html_url' => $identity['html_url'],
            ],
        );

        $this->accounts->setCredentials($account, $merged);
        $this->accounts->markConnected($account);
        $this->accounts->recordAuthSuccess($account->fresh() ?? $account);

        return $account->fresh() ?? $account;
    }

    private function deactivateOtherAccounts(User $owner, string $githubUserId): void
    {
        $others = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'github')
            ->where('external_account_id', '!=', $githubUserId)
            ->where('status', IntegrationAccountStatus::Connected)
            ->get();

        foreach ($others as $other) {
            $this->oauth->revokeSafely($this->revokeToken($other));
            $this->accounts->disconnect($other);
        }
    }

    /**
     * @param  list<string>  $granted
     * @return list<string>
     */
    private function mergeGrantedScopes(User $owner, string $githubUserId, array $granted): array
    {
        $existing = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'github')
            ->where('external_account_id', $githubUserId)
            ->first();

        $existingScopes = is_array($existing?->scopes) ? $existing->scopes : [];

        return $this->oauth->normalizeScopes(array_merge($existingScopes, $granted));
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     * @return list<string>
     */
    private function grantedScopes(array $tokenResponse): array
    {
        $raw = $tokenResponse['scope'] ?? $this->oauth->requestedScopes();
        $scopes = is_string($raw) ? preg_split('/[,\s]+/', $raw) ?: [] : (array) $raw;

        return $this->oauth->normalizeScopes($scopes);
    }

    private function revokeToken(IntegrationAccount $account): ?string
    {
        $envelope = $this->accounts->getCredentials($account);

        return filled($envelope['access_token'] ?? null) ? (string) $envelope['access_token'] : null;
    }
}
