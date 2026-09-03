<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\Contracts\AiProviderClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicClient implements AiProviderClient
{
    public function provider(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Claude';
    }

    public function listModels(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->get('https://api.anthropic.com/v1/models');

        if (! $response->successful()) {
            throw new RuntimeException(
                $response->json('error.message')
                    ?: 'Anthropic request failed with status '.$response->status()
            );
        }

        $models = collect($response->json('data', []))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['display_name'] ?? $item['id'] ?? ''),
            ])
            ->filter(fn (array $model): bool => $model['id'] !== '')
            ->values()
            ->all();

        if ($models === []) {
            throw new RuntimeException('Anthropic returned no models for this API key.');
        }

        return $models;
    }
}
