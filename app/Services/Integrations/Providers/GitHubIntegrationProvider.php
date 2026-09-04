<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Integrations\GitHub\GitHubCredentialService;
use App\Services\Integrations\GitHub\GitHubOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;

final class GitHubIntegrationProvider implements IntegrationProvider
{
    public function __construct(
        private readonly GitHubOAuthService $oauth,
        private readonly GitHubCredentialService $credentials,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function key(): string
    {
        return 'github';
    }

    public function displayName(): string
    {
        return 'GitHub';
    }

    public function capabilities(): array
    {
        return [
            UserCapability::GITHUB,
        ];
    }

    public function requiresAccount(): bool
    {
        return true;
    }

    public function supportsConnect(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function status(User $owner): IntegrationStatus
    {
        $configured = $this->oauth->isConfigured();
        $account = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', $this->key())
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [IntegrationAccountStatus::Connected->value])
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();

        if (! $configured) {
            return new IntegrationStatus(
                provider: $this->key(),
                displayName: $this->displayName(),
                state: IntegrationAccountStatus::Disconnected,
                label: 'Not configured',
                diagnosticMessage: 'GitHub OAuth client configuration is missing.',
                actions: [
                    ['key' => 'connect', 'available' => false, 'label' => 'Connect GitHub'],
                ],
                configured: false,
            );
        }

        $state = $account?->status ?? IntegrationAccountStatus::Disconnected;
        $envelope = $account !== null ? $this->accounts->getCredentials($account) : [];
        $tokenHealth = $account !== null ? $this->credentials->healthFromEnvelope($envelope) : 'missing';
        $scopes = is_array($account?->scopes) ? $account->scopes : [];
        $metadata = is_array($account?->metadata) ? $account->metadata : [];
        $login = isset($metadata['login']) ? (string) $metadata['login'] : null;

        $label = match ($state) {
            IntegrationAccountStatus::Connected => 'Connected',
            IntegrationAccountStatus::Error => 'Reconnect required',
            IntegrationAccountStatus::Revoked => 'Reconnect required',
            IntegrationAccountStatus::Connecting => 'Connecting',
            default => 'Disconnected',
        };

        $diagnostic = match ($state) {
            IntegrationAccountStatus::Connected => $this->connectedDiagnostic($tokenHealth),
            IntegrationAccountStatus::Error, IntegrationAccountStatus::Revoked => 'Reconnect required.',
            default => 'Owner Conversation AI can use GitHub tools after connect.',
        };

        if ($state === IntegrationAccountStatus::Connected && $tokenHealth === 'needs_reconnect') {
            $label = 'Reconnect required';
        }

        $canConnect = $state !== IntegrationAccountStatus::Connecting;
        $canDisconnect = in_array($state, [
            IntegrationAccountStatus::Connected,
            IntegrationAccountStatus::Error,
            IntegrationAccountStatus::Revoked,
        ], true);

        $actions = [
            [
                'key' => $state === IntegrationAccountStatus::Disconnected ? 'connect' : 'reconnect',
                'available' => $canConnect,
                'label' => $state === IntegrationAccountStatus::Disconnected ? 'Connect GitHub' : 'Reconnect',
            ],
            ['key' => 'disconnect', 'available' => $canDisconnect, 'label' => 'Disconnect'],
        ];

        $accountLabel = $login !== null && $login !== '' ? '@'.$login : $account?->external_account_email;

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $label,
            accountLabel: $accountLabel,
            scopes: $scopes,
            lastSuccessAt: optional($account?->last_success_at)?->toIso8601String(),
            lastErrorAt: optional($account?->last_error_at)?->toIso8601String(),
            diagnosticMessage: $diagnostic,
            actions: $actions,
            configured: true,
            connectedAt: optional($account?->connected_at)?->toIso8601String(),
            tokenHealth: $account !== null ? $tokenHealth : null,
            scopeLabels: $this->oauth->scopeLabels($scopes),
            lastErrorCode: $account?->last_error_code,
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        $envelope = $this->accounts->getCredentials($account);
        $this->oauth->revokeSafely(filled($envelope['access_token'] ?? null) ? (string) $envelope['access_token'] : null);
    }

    private function connectedDiagnostic(string $health): string
    {
        return match ($health) {
            'refreshable' => 'Access token will refresh when needed.',
            'needs_reconnect' => 'Reconnect required.',
            'missing' => 'Credentials are missing. Reconnect required.',
            default => 'Credentials are stored encrypted. No live GitHub call on this page.',
        };
    }
}
