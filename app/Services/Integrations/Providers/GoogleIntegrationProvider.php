<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Users\UserCapability;

final class GoogleIntegrationProvider implements IntegrationProvider
{
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
        return true;
    }

    public function status(User $owner): IntegrationStatus
    {
        $account = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', $this->key())
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();

        $state = $account?->status ?? IntegrationAccountStatus::Disconnected;

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $state === IntegrationAccountStatus::Connected
                ? 'Connected'
                : 'Disconnected',
            accountLabel: $account?->external_account_email,
            scopes: is_array($account?->scopes) ? $account->scopes : [],
            lastSuccessAt: optional($account?->last_success_at)?->toIso8601String(),
            lastErrorAt: optional($account?->last_error_at)?->toIso8601String(),
            diagnosticMessage: $state === IntegrationAccountStatus::Connected
                ? null
                : 'Google Calendar / Gmail integration not connected.',
            actions: [
                ['key' => 'connect', 'available' => false, 'label' => 'Available next milestone'],
                ['key' => 'disconnect', 'available' => $state === IntegrationAccountStatus::Connected, 'label' => 'Disconnect'],
            ],
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        // Local state only. Remote Google revoke is M17.
    }
}
