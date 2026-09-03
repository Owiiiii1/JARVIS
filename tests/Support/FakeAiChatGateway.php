<?php

namespace Tests\Support;

use App\Models\AiRoleSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;

final class FakeAiChatGateway implements AiChatGateway
{
    /** @var list<array{role_key: string, provider: string, model: string, request: AiChatRequest}> */
    public array $calls = [];

    public string $responseText = 'Fake assistant reply';

    public ?\Throwable $exception = null;

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
}
