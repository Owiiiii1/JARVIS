<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Integrations\Google\GoogleCredentialService;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;

final class GoogleIntegrationProvider implements IntegrationProvider
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleCredentialService $credentials,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function key(): string
    {
        return 'google';
    }

    public function displayName(): string
    {
        return 'Google';
    }

    public function capabilities(): array
    {
        return [
            UserCapability::GOOGLE_CALENDAR,
            UserCapability::GMAIL,
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
                diagnosticMessage: 'Google client configuration is missing.',
                actions: [
                    ['key' => 'connect', 'available' => false, 'label' => 'Connect Google'],
                ],
                configured: false,
            );
        }

        $state = $account?->status ?? IntegrationAccountStatus::Disconnected;
        $envelope = $account !== null ? $this->accounts->getCredentials($account) : [];
        $tokenHealth = $account !== null ? $this->credentials->healthFromEnvelope($envelope) : 'missing';
        $scopes = is_array($account?->scopes) ? $account->scopes : [];

        $label = match ($state) {
            IntegrationAccountStatus::Connected => 'Connected',
            IntegrationAccountStatus::Error => 'Error',
            IntegrationAccountStatus::Revoked => 'Revoked',
            IntegrationAccountStatus::Connecting => 'Connecting',
            default => 'Disconnected',
        };

        $diagnostic = match ($state) {
            IntegrationAccountStatus::Connected => $this->connectedDiagnostic($tokenHealth),
            IntegrationAccountStatus::Error => 'Reconnect required.',
            IntegrationAccountStatus::Revoked => 'Reconnect required.',
            default => 'Google Calendar / Gmail tools will use this account later.',
        };

        $canConnect = $state !== IntegrationAccountStatus::Connecting;
        $canDisconnect = in_array($state, [
            IntegrationAccountStatus::Connected,
            IntegrationAccountStatus::Error,
            IntegrationAccountStatus::Revoked,
        ], true);
        $calendarEnabled = $state === IntegrationAccountStatus::Connected
            && $this->oauth->hasCalendarScope($scopes);
        $gmailEnabled = $state === IntegrationAccountStatus::Connected
            && $this->oauth->hasGmailScope($scopes);
        $canEnableCalendar = $state === IntegrationAccountStatus::Connected && ! $calendarEnabled;
        $canEnableGmail = $state === IntegrationAccountStatus::Connected && ! $gmailEnabled;

        $actions = [
            [
                'key' => $state === IntegrationAccountStatus::Disconnected ? 'connect' : 'reconnect',
                'available' => $canConnect,
                'label' => $state === IntegrationAccountStatus::Disconnected ? 'Connect Google' : 'Reconnect',
            ],
        ];

        if ($canEnableCalendar) {
            $actions[] = ['key' => 'enable_calendar', 'available' => true, 'label' => 'Enable Calendar'];
        }

        if ($canEnableGmail) {
            $actions[] = ['key' => 'enable_gmail', 'available' => true, 'label' => 'Enable Gmail'];
        }

        $actions[] = ['key' => 'disconnect', 'available' => $canDisconnect, 'label' => 'Disconnect'];

        if ($state === IntegrationAccountStatus::Connected && ! $calendarEnabled && ! $gmailEnabled) {
            $diagnostic = 'Calendar and Gmail permission required.';
        } elseif ($state === IntegrationAccountStatus::Connected && ! $calendarEnabled) {
            $diagnostic = 'Calendar permission required.';
        } elseif ($state === IntegrationAccountStatus::Connected && ! $gmailEnabled) {
            $diagnostic = 'Gmail permission required.';
        }

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $label,
            accountLabel: $account?->external_account_email,
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
            capabilityStates: [
                [
                    'key' => 'identity',
                    'label' => 'Identity',
                    'state' => $state === IntegrationAccountStatus::Connected ? 'connected' : 'disconnected',
                ],
                [
                    'key' => 'calendar',
                    'label' => 'Calendar',
                    'state' => $calendarEnabled ? 'enabled' : ($state === IntegrationAccountStatus::Connected ? 'permission_required' : 'not_enabled'),
                ],
                [
                    'key' => 'gmail',
                    'label' => 'Gmail',
                    'state' => $gmailEnabled ? 'enabled' : ($state === IntegrationAccountStatus::Connected ? 'permission_required' : 'not_enabled'),
                ],
            ],
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        $envelope = $this->accounts->getCredentials($account);
        $token = filled($envelope['refresh_token'] ?? null)
            ? (string) $envelope['refresh_token']
            : (string) ($envelope['access_token'] ?? '');

        $this->oauth->revokeSafely($token !== '' ? $token : null);
    }

    private function connectedDiagnostic(string $health): string
    {
        return match ($health) {
            'refreshable' => 'Access token will refresh when needed.',
            'needs_reconnect' => 'Reconnect recommended.',
            'missing' => 'Credentials are missing. Reconnect required.',
            default => 'Credentials are stored encrypted.',
        };
    }
}
