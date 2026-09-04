<?php

namespace App\Services\Ai;

use App\Services\Ai\Clients\AnthropicClient;
use App\Services\Ai\Clients\GeminiClient;
use App\Services\Ai\Clients\OpenAiClient;
use App\Services\Ai\Contracts\AiProviderClient;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\Exceptions\AiConfigurationException;
use InvalidArgumentException;

class AiProviderManager
{
    /** @var array<string, AiProviderClient> */
    private array $clients;

    public function __construct()
    {
        $this->clients = [
            'openai' => new OpenAiClient,
            'anthropic' => new AnthropicClient,
            'gemini' => new GeminiClient,
        ];
    }

    /**
     * @return array<int, array{provider: string, label: string, supports_chat: bool, supports_tools: bool, supports_vision: bool}>
     */
    public function providers(): array
    {
        return array_values(array_map(
            fn (AiProviderClient $client): array => [
                'provider' => $client->provider(),
                'label' => $client->label(),
                'supports_chat' => $client->supportsChat(),
                'supports_tools' => $client->supportsTools(),
                'supports_vision' => $client->supportsVision(),
            ],
            $this->clients
        ));
    }

    public function supportsChat(string $provider): bool
    {
        return $this->client($provider)->supportsChat();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listModels(string $provider, string $apiKey): array
    {
        $client = $this->client($provider);
        $models = $client->listModels($apiKey);

        $seen = [];
        $normalized = [];
        foreach ($models as $model) {
            $id = trim((string) ($model['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = [
                'id' => $id,
                'name' => trim((string) ($model['name'] ?? '')) ?: $id,
            ];
        }

        return $normalized;
    }

    public function chat(string $provider, string $apiKey, AiChatRequest $request): AiChatResponse
    {
        $client = $this->client($provider);

        if (! $client->supportsChat()) {
            throw new AiConfigurationException('Chat is not implemented for provider '.$provider.'.');
        }

        if ($request->hasTools() && ! $client->supportsTools()) {
            throw new AiConfigurationException('Tools are not implemented for provider '.$provider.'.');
        }

        if ($request->hasImageParts() && ! $client->supportsVision()) {
            throw new AiConfigurationException('vision_not_supported');
        }

        return $client->chat($apiKey, $request);
    }

    public function supportsTools(string $provider): bool
    {
        return $this->client($provider)->supportsTools();
    }

    public function supportsVision(string $provider): bool
    {
        return $this->client($provider)->supportsVision();
    }

    public function client(string $provider): AiProviderClient
    {
        if (! isset($this->clients[$provider])) {
            throw new InvalidArgumentException('Unsupported AI provider: '.$provider);
        }

        return $this->clients[$provider];
    }
}
