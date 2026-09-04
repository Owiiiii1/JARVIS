<?php

namespace App\Services\Integrations\Providers;

use App\Enums\IntegrationAccountStatus;
use App\Models\IntegrationAccount;
use App\Models\TelegramBotSetting;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\DTO\IntegrationStatus;
use App\Services\Users\UserCapability;
use Illuminate\Support\Facades\Schema;

final class TelegramIntegrationProvider implements IntegrationProvider
{
    public function key(): string
    {
        return 'telegram';
    }

    public function displayName(): string
    {
        return 'Telegram';
    }

    public function capabilities(): array
    {
        return [
            UserCapability::TELEGRAM_DM,
            UserCapability::TELEGRAM_GROUPS,
        ];
    }

    public function requiresAccount(): bool
    {
        return false;
    }

    public function supportsConnect(): bool
    {
        return false;
    }

    public function status(User $owner): IntegrationStatus
    {
        $setting = $this->setting();
        $hasToken = filled($setting?->bot_token);
        $connected = (bool) ($setting?->is_connected);
        $webhookSet = (bool) ($setting?->is_webhook_set);
        $hasError = filled($setting?->last_error);

        $state = match (true) {
            $hasError && ! $connected => IntegrationAccountStatus::Error,
            $connected => IntegrationAccountStatus::Connected,
            $hasToken => IntegrationAccountStatus::Connecting,
            default => IntegrationAccountStatus::Disconnected,
        };

        $label = match ($state) {
            IntegrationAccountStatus::Connected => $webhookSet ? 'Connected' : 'Connected (webhook not set)',
            IntegrationAccountStatus::Connecting => 'Configured',
            IntegrationAccountStatus::Error => 'Error',
            default => 'Not configured',
        };

        $diagnostics = [];
        if ($setting?->bot_username) {
            $diagnostics[] = '@'.$setting->bot_username;
        }
        if ($webhookSet) {
            $diagnostics[] = 'Webhook set';
        } elseif ($hasToken) {
            $diagnostics[] = 'Webhook not set';
        }
        $groups = TelegramGroup::query()->count();
        $diagnostics[] = $groups.' groups';

        return new IntegrationStatus(
            provider: $this->key(),
            displayName: $this->displayName(),
            state: $state,
            label: $label,
            accountLabel: $setting?->bot_username ? '@'.$setting->bot_username : null,
            scopes: [],
            lastSuccessAt: optional($setting?->last_checked_at)?->toIso8601String(),
            lastErrorAt: $hasError ? optional($setting?->last_checked_at)?->toIso8601String() : null,
            diagnosticMessage: implode(' · ', $diagnostics),
            actions: [],
        );
    }

    public function disconnect(IntegrationAccount $account): void
    {
        // Telegram credentials stay in telegram_bot_settings. No account row.
    }

    private function setting(): ?TelegramBotSetting
    {
        if (! Schema::hasTable('telegram_bot_settings')) {
            return null;
        }

        return TelegramBotSetting::query()->first();
    }
}
