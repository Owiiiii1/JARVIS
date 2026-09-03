<?php

namespace Tests\Support;

use App\Models\AiRoleSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;

final class FakeAiChatGateway implements AiChatGateway
{
    /** @var list<array{role_key: string, provider: string, model: string, request: AiChatRequest}> */
    public array $calls = [];

    public string $responseText = 'Fake assistant reply';

    public ?\Throwable $exception = null;

    public bool $supportsTools = true;

    /** @var list<AiChatResponse|\Closure> */
    public array $script = [];

    public function chat(AiRoleSetting $configuration, AiChatRequest $request): AiChatResponse
    {
        $this->calls[] = [
            'role_key' => $configuration->roleKey()->value,
            'provider' => (string) $configuration->provider,
            'model' => (string) $configuration->model,
            'request' => $request,
        ];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        if ($this->script !== []) {
            $next = array_shift($this->script);

            if ($next instanceof \Closure) {
                return $next($configuration, $request);
            }

            return $next;
        }

        return new AiChatResponse(
            text: $this->responseText,
            provider: (string) $configuration->provider,
            model: (string) $configuration->model,
            finishReason: 'stop',
            inputTokens: 11,
            outputTokens: 7,
            totalTokens: 18,
        );
    }

    public function supportsTools(AiRoleSetting $configuration): bool
    {
        return $this->supportsTools;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function queueToolThenText(string $tool, array $arguments, string $finalText, string $callId = 'call_1'): void
    {
        $this->script[] = new AiChatResponse(
            text: '',
            provider: 'fake',
            model: 'fake-model',
            finishReason: 'tool_calls',
            toolCalls: [new ToolCall($callId, $tool, $arguments)],
        );
        $this->script[] = new AiChatResponse(
            text: $finalText,
            provider: 'fake',
            model: 'fake-model',
            finishReason: 'stop',
        );
    }
}
