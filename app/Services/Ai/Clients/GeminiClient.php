<?php

namespace App\Services\Ai\Clients;

use App\Services\Ai\AiProviderMessageNormalizer;
use App\Services\Ai\Contracts\AiProviderClient;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    public function supportsTools(): bool
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
        $payload = [
            'contents' => $this->contents($request),
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

        if ($request->hasTools()) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(
                    fn (ToolDefinition $tool): array => $this->functionDeclaration($tool),
                    $request->tools
                ),
            ]];
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
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        $chunks = [];
        $toolCalls = [];

        foreach ($parts as $index => $part) {
            if (! is_array($part)) {
                continue;
            }

            if (isset($part['text'])) {
                $chunks[] = trim((string) $part['text']);
            }

            $functionCall = $part['functionCall'] ?? $part['function_call'] ?? null;

            if (is_array($functionCall) && filled($functionCall['name'] ?? null)) {
                $args = $functionCall['args'] ?? $functionCall['arguments'] ?? [];

                if (is_string($args)) {
                    $decoded = json_decode($args, true);
                    $args = is_array($decoded) ? $decoded : [];
                }

                if (! is_array($args)) {
                    $args = [];
                }

                $toolCalls[] = new ToolCall(
                    id: (string) ($functionCall['id'] ?? 'gemini_'.$index.'_'.Str::lower(Str::random(8))),
                    name: (string) $functionCall['name'],
                    arguments: $args,
                );
            }
        }

        $text = trim(implode("\n", array_filter($chunks)));

        if ($text === '' && $toolCalls === []) {
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
            toolCalls: $toolCalls,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contents(AiChatRequest $request): array
    {
        if ($this->hasToolMessages($request)) {
            $contents = [];

            foreach ($request->messages as $message) {
                $content = $this->contentFromMessage($message);

                if ($content !== null) {
                    $contents[] = $content;
                }
            }

            if ($contents === []) {
                return [[
                    'role' => 'user',
                    'parts' => [['text' => 'Please proceed.']],
                ]];
            }

            if (($contents[0]['role'] ?? '') !== 'user') {
                array_unshift($contents, [
                    'role' => 'user',
                    'parts' => [['text' => '[conversation start]']],
                ]);
            }

            return $contents;
        }

        $messages = AiProviderMessageNormalizer::ensureStartsWithUser(
            AiProviderMessageNormalizer::mergeConsecutive(
                AiProviderMessageNormalizer::dialogue($request)
            )
        );

        return array_map(static function (array $message): array {
            return [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ];
        }, $messages);
    }

    /**
     * @return array{role: string, parts: list<array<string, mixed>>}|null
     */
    private function contentFromMessage(AiChatMessage $message): ?array
    {
        if ($message->toolResponse !== null || $message->role === 'tool') {
            $name = $message->toolName ?: 'unknown_tool';
            $payload = $message->toolResponse ?? [];

            return [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $name,
                        'response' => $payload === [] ? (object) [] : $payload,
                    ],
                ]],
            ];
        }

        if ($message->toolCalls !== []) {
            $parts = [];

            if (trim($message->content) !== '') {
                $parts[] = ['text' => $message->content];
            }

            foreach ($message->toolCalls as $call) {
                $parts[] = [
                    'functionCall' => [
                        'name' => $call->name,
                        'args' => $call->arguments === [] ? (object) [] : $call->arguments,
                    ],
                ];
            }

            return [
                'role' => 'model',
                'parts' => $parts,
            ];
        }

        if (trim($message->content) === '') {
            return null;
        }

        return [
            'role' => $message->role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $message->content]],
        ];
    }

    private function hasToolMessages(AiChatRequest $request): bool
    {
        foreach ($request->messages as $message) {
            if ($message->isToolMessage()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    private function functionDeclaration(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $tool->parameters,
        ];
    }
}
