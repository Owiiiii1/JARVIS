<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Users\UserCapability;

final class ElevenLabsIntegrationProvider implements IntegrationProvider
{
    public function key(): string
    {
        return 'elevenlabs';
    }

    public function displayName(): string
    {
        return 'ElevenLabs';
    }

    public function capabilities(): array
    {
        return [
            UserCapability::VOICE,
        ];
    }

    public function requiresAccount(): bool
    {
        return true;
    }

    public function supportsConnect(): bool
    {
        return false;
    }

    public function status(User $owner): IntegrationStatus
    {
        $account = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', $this->key())
            ->orderByDesc('id')
            ->first();

        $state = $account?->status ?? IntegrationAccountStatus::Disconnected;

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $state === IntegrationAccountStatus::Connected
                ? 'Connected'
                : 'Not configured',
            accountLabel: null,
            scopes: [],
            lastSuccessAt: optional($account?->last_success_at)?->toIso8601String(),
            lastErrorAt: optional($account?->last_error_at)?->toIso8601String(),
            diagnosticMessage: 'Voice integration later.',
            actions: [],
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        // Local state only. No remote revoke in M16.
    }
}
