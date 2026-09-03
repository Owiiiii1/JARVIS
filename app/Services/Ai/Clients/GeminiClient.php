<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\AiProviderMessageNormalizer;
use App\Services\Ai\Contracts\AiProviderClient;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;

class GeminiClient implements AiProviderClient
{
    public function provider(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Gemini';
    }

    public function supportsChat(): bool
    {
        return true;
    }

    public function listModels(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->acceptJson()
            ->get('https://generativelanguage.googleapis.com/v1beta/models', [
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            throw new AiProviderException(
                $response->json('error.message')
                    ?: 'Gemini request failed with status '.$response->status()
            );
        }

        $models = collect($response->json('models', []))
            ->map(function (array $item): array {
                $rawName = (string) ($item['name'] ?? '');
                $id = str_starts_with($rawName, 'models/')
                    ? substr($rawName, 7)
                    : $rawName;

                return [
                    'id' => $id,
                    'name' => (string) ($item['displayName'] ?? $id),
                ];
            })
            ->filter(fn (array $model): bool => $model['id'] !== '')
            ->values()
            ->all();

        if ($models === []) {
            throw new AiProviderException('Gemini returned no models for this API key.');
        }

        return $models;
    }

    public function chat(string $apiKey, AiChatRequest $request): AiChatResponse
    {
        $messages = AiProviderMessageNormalizer::ensureStartsWithUser(
            AiProviderMessageNormalizer::mergeConsecutive(
                AiProviderMessageNormalizer::dialogue($request)
            )
        );

        $contents = array_map(static function (array $message): array {
            return [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }, $messages);

        $payload = [
            'contents' => $contents,
        ];

        if (filled($request->systemPrompt)) {
            $payload['system_instruction'] = [
                'parts' => [['text' => $request->systemPrompt]],
            ];
        }

        $generationConfig = [];

        if ($request->temperature() !== null) {
            $generationConfig['temperature'] = $request->temperature();
        }

        if ($request->maxTokens() !== null) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens();
        }

        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        $model = ltrim($request->model, '/');
        $model = str_starts_with($model, 'models/') ? substr($model, 7) : $model;

        $response = Http::timeout(60)
            ->acceptJson()
            ->withQueryParameters(['key' => $apiKey])
            ->post(
                'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',
                $payload
            );

        if (! $response->successful()) {
            throw new AiProviderException(
                $response->json('error.message')
                    ?: 'Gemini chat request failed with status '.$response->status()
            );
        }

        $body = $response->json() ?? [];
        $chunks = [];

        foreach ($body['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (is_array($part) && isset($part['text'])) {
                $chunks[] = trim((string) $part['text']);
            }
        }

        $text = trim(implode("\n", array_filter($chunks)));

        if ($text === '') {
            throw new AiProviderException('Gemini returned an empty assistant response.');
        }

        $usage = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];

        return new AiChatResponse(
            text: $text,
            provider: $this->provider(),
            model: $request->model,
            finishReason: is_string($body['candidates'][0]['finishReason'] ?? null)
                ? $body['candidates'][0]['finishReason']
                : null,
            inputTokens: isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            outputTokens: isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
            totalTokens: isset($usage['totalTokenCount']) ? (int) $usage['totalTokenCount'] : null,
            metadata: [
                'model_version' => $body['modelVersion'] ?? null,
            ],
        );
    }
}
