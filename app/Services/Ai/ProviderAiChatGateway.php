<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;
use App\Models\AiRoleSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\Exceptions\AiConfigurationException;

final class ProviderAiChatGateway implements AiChatGateway
{
    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    public function chat(AiRoleSetting $configuration, AiChatRequest $request): AiChatResponse
    {
        $provider = (string) $configuration->provider;

        if ($provider === '') {
            throw new AiConfigurationException('AI configuration has no provider.');
        }

        $setting = AiProviderSetting::query()
            ->where('provider', $provider)
            ->first();

        if ($setting === null || ! filled($setting->api_key)) {
            throw new AiConfigurationException('AI provider credentials are missing.');
        }

        if (! $setting->is_connected) {
            throw new AiConfigurationException('AI provider is not connected.');
        }

        if ($request->hasTools() && ! $this->providers->supportsTools($provider)) {
            throw new AiConfigurationException('AI provider does not support tools.');
        }

        return $this->providers->chat($provider, (string) $setting->api_key, $request);
    }

    public function supportsTools(AiRoleSetting $configuration): bool
    {
        $provider = (string) $configuration->provider;

        if ($provider === '') {
            return false;
        }

        return $this->providers->supportsTools($provider);
    }
}
