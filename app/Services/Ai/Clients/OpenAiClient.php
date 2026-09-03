<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\Contracts\AiProviderClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient implements AiProviderClient
{
    public function provider(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function listModels(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->acceptJson()
            ->get('https://api.openai.com/v1/models');

        if (! $response->successful()) {
            throw new RuntimeException(
                $response->json('error.message')
                    ?: 'OpenAI request failed with status '.$response->status()
            );
        }

        $models = collect($response->json('data', []))
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['id'] ?? ''),
            ])
            ->filter(fn (array $model): bool => $model['id'] !== '')
            ->values()
            ->all();

        if ($models === []) {
            throw new RuntimeException('OpenAI returned no models for this API key.');
        }

        return $models;
    }
}
