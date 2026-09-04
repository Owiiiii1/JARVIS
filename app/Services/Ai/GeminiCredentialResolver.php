<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;

final class GeminiCredentialResolver
{
    public function setting(): ?AiProviderSetting
    {
        return AiProviderSetting::query()->where('provider', 'gemini')->first();
    }

    public function isConfigured(): bool
    {
        $setting = $this->setting();

        return $setting !== null && $setting->is_connected && filled($setting->api_key);
    }

    public function apiKey(): string
    {
        if (! $this->isConfigured()) {
            return '';
        }

        return trim((string) $this->setting()?->api_key);
    }
}
