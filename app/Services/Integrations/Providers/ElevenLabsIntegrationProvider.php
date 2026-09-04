<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Users\UserCapability;
use App\Services\Voice\VoiceSettingsService;

final class ElevenLabsIntegrationProvider implements IntegrationProvider
{
    public function __construct(
        private readonly VoiceSettingsService $voiceSettings,
    ) {}

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
        $configured = $this->voiceSettings->elevenLabsApiKey() !== '';
        $state = $configured
            ? IntegrationAccountStatus::Connected
            : IntegrationAccountStatus::Disconnected;

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $configured ? 'Configured' : 'Not configured',
            accountLabel: null,
            scopes: [],
            lastSuccessAt: null,
            lastErrorAt: null,
            diagnosticMessage: $configured
                ? 'TTS adapter ready. Voice Runtime uses Admin Voice/Speech settings. No live Test Connection.'
                : 'Not configured. Add an ElevenLabs key under Integrations → Voice/Speech.',
            actions: [],
            configured: $configured,
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        // Local state only. No remote revoke in M16.
    }
}
