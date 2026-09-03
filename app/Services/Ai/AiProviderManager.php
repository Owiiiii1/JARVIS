<?php

namespace App\Services\Ai;

use App\Services\Ai\Clients\AnthropicClient;
use App\Services\Ai\Clients\GeminiClient;
use App\Services\Ai\Clients\OpenAiClient;
use App\Services\Ai\Contracts\AiProviderClient;
use InvalidArgumentException;

class AiProviderManager
{
    /** @var array<string, AiProviderClient> */
    private array $clients;

    public function __construct()
    {
        $this->clients = [
            'openai' => new OpenAiClient(),
            'anthropic' => new AnthropicClient(),
            'gemini' => new GeminiClient(),
        ];
    }

    /**
     * @return array<int, array{provider: string, label: string}>
     */
    public function providers(): array
    {
        return array_values(array_map(
            fn (AiProviderClient $client): array => [
                'provider' => $client->provider(),
                'label' => $client->label(),
            ],
            $this->clients
        ));
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

    private function client(string $provider): AiProviderClient
    {
        if (! isset($this->clients[$provider])) {
            throw new InvalidArgumentException('Unsupported AI provider: '.$provider);
        }

        return $this->clients[$provider];
    }
}
