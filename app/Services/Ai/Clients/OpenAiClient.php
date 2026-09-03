<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\AiProviderMessageNormalizer;
use App\Services\Ai\Contracts\AiProviderClient;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

    public function supportsChat(): bool
    {
        return true;
    }

    public function listModels(string $apiKey): array
    {
        $response = Http::timeout(15)
            ->withToken($apiKey)
            ->acceptJson()
            ->get('https://api.openai.com/v1/models');

        if (! $response->successful()) {
            throw new AiProviderException(
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
            throw new AiProviderException('OpenAI returned no models for this API key.');
        }

        return $models;
    }

    public function chat(string $apiKey, AiChatRequest $request): AiChatResponse
    {
        $response = $this->postResponses($apiKey, $request);

        if ($this->shouldFallbackToCompletions($response)) {
            $response = $this->postChatCompletions($apiKey, $request);
        }

        if (! $response->successful()) {
            throw new AiProviderException(
                $response->json('error.message')
                    ?: 'OpenAI chat request failed with status '.$response->status()
            );
        }

        $payload = $response->json() ?? [];
        $text = $this->extractText($payload);

        if ($text === '') {
            throw new AiProviderException('OpenAI returned an empty assistant response.');
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new AiChatResponse(
            text: $text,
            provider: $this->provider(),
            model: (string) ($payload['model'] ?? $request->model),
            finishReason: $this->extractFinishReason($payload),
            inputTokens: $this->intOrNull($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null),
            outputTokens: $this->intOrNull($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null),
            totalTokens: $this->intOrNull($usage['total_tokens'] ?? null),
            metadata: [
                'endpoint' => str_contains((string) $response->effectiveUri(), '/responses') ? 'responses' : 'chat_completions',
                'id' => $payload['id'] ?? null,
            ],
        );
    }

    private function postResponses(string $apiKey, AiChatRequest $request): Response
    {
        $payload = [
            'model' => $request->model,
            'instructions' => $request->systemPrompt,
            'input' => AiProviderMessageNormalizer::dialogue($request),
            'store' => false,
        ];

        if ($request->temperature() !== null) {
            $payload['temperature'] = $request->temperature();
        }

        if ($request->maxTokens() !== null) {
            $payload['max_output_tokens'] = $request->maxTokens();
        }

        return Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/responses', $payload);
    }

    private function postChatCompletions(string $apiKey, AiChatRequest $request): Response
    {
        $messages = [];

        if (filled($request->systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ];
        }

        foreach (AiProviderMessageNormalizer::dialogue($request) as $message) {
            $messages[] = $message;
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'store' => false,
        ];

        if ($request->temperature() !== null) {
            $payload['temperature'] = $request->temperature();
        }

        if ($request->maxTokens() !== null) {
            $payload['max_tokens'] = $request->maxTokens();
        }

        return Http::timeout(60)
            ->withToken($apiKey)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', $payload);
    }

    private function shouldFallbackToCompletions(Response $response): bool
    {
        if ($response->successful()) {
            return false;
        }

        $status = $response->status();
        $message = strtolower((string) ($response->json('error.message') ?: $response->body()));

        return in_array($status, [404, 405], true)
            || str_contains($message, 'unknown endpoint')
            || str_contains($message, 'not found')
            || str_contains($message, 'unrecognized request argument');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractText(array $payload): string
    {
        $direct = trim((string) ($payload['output_text'] ?? ''));

        if ($direct !== '') {
            return $direct;
        }

        $choices = $payload['choices'][0]['message']['content'] ?? null;

        if (is_string($choices) && trim($choices) !== '') {
            return trim($choices);
        }

        $chunks = [];

        foreach ($payload['output'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($item['content'] ?? [] as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $chunks[] = trim($text);
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractFinishReason(array $payload): ?string
    {
        $fromChoice = $payload['choices'][0]['finish_reason'] ?? null;

        if (is_string($fromChoice) && $fromChoice !== '') {
            return $fromChoice;
        }

        $status = $payload['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
