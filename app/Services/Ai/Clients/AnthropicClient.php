<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\AiProviderMessageNormalizer;
use App\Services\Ai\Contracts\AiProviderClient;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;

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

    public function supportsChat(): bool
    {
        return true;
    }

    public function supportsTools(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
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
            throw new AiProviderException(
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
            throw new AiProviderException('Anthropic returned no models for this API key.');
        }

        return $models;
    }

    public function chat(string $apiKey, AiChatRequest $request): AiChatResponse
    {
        if ($request->hasTools()) {
            throw new AiProviderException('Anthropic client does not support tools yet.');
        }

        if ($request->hasImageParts()) {
            throw new AiProviderException('vision_not_supported');
        }

        $messages = AiProviderMessageNormalizer::ensureStartsWithUser(
            AiProviderMessageNormalizer::mergeConsecutive(
                AiProviderMessageNormalizer::dialogue($request)
            )
        );

        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens() ?? 2048,
            'messages' => $messages,
        ];

        if (filled($request->systemPrompt)) {
            $payload['system'] = $request->systemPrompt;
        }

        if ($request->temperature() !== null) {
            $payload['temperature'] = $request->temperature();
        }

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if (! $response->successful()) {
            throw new AiProviderException(
                $response->json('error.message')
                    ?: 'Anthropic chat request failed with status '.$response->status()
            );
        }

        $body = $response->json() ?? [];
        $chunks = [];

        foreach ($body['content'] ?? [] as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text') {
                $chunks[] = trim((string) ($part['text'] ?? ''));
            }
        }

        $text = trim(implode("\n", array_filter($chunks)));

        if ($text === '') {
            throw new AiProviderException('Anthropic returned an empty assistant response.');
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiChatResponse(
            text: $text,
            provider: $this->provider(),
            model: (string) ($body['model'] ?? $request->model),
            finishReason: is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : null,
            inputTokens: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            outputTokens: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            totalTokens: isset($usage['input_tokens'], $usage['output_tokens'])
                ? (int) $usage['input_tokens'] + (int) $usage['output_tokens']
                : null,
            metadata: [
                'id' => $body['id'] ?? null,
            ],
        );
    }
}
