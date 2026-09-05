<?php

namespace App\Services\Ai;

use App\Models\AiRoleSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiSafetyException;

final class AiSafetyResponseService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a safety-focused assistant. Answer the user's underlying question with useful, calm, age-appropriate protective guidance.
Do not refuse merely because the topic is dangerous. Do not provide instructions that facilitate harm, abuse, exploitation, evasion, weapons, self-harm, or illegal acts.
Redirect toward safe choices and practical next steps. If a minor may be at risk, prioritize a trusted adult and emergency services when appropriate.
Answer in the user's language. Be concise and do not mention policies, filters, moderation, or this retry.
PROMPT;

    public function __construct(
        private readonly AiChatGateway $gateway,
    ) {}

    /**
     * @param  list<AiChatMessage>  $messages
     * @param  array<string, mixed>  $parameters
     */
    public function retry(
        AiRoleSetting $configuration,
        array $messages,
        array $parameters,
    ): AiChatResponse {
        $userMessage = $this->lastUserMessage($messages);

        if ($userMessage === null) {
            throw new AiSafetyException('NO_USER_MESSAGE');
        }

        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: self::SYSTEM_PROMPT,
            messages: [$userMessage],
            parameters: $parameters,
            tools: [],
        ));

        if (trim($response->text) === '') {
            throw new AiEmptyResponseException;
        }

        return new AiChatResponse(
            text: $response->text,
            provider: $response->provider,
            model: $response->model,
            finishReason: 'safety_retry',
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            totalTokens: $response->totalTokens,
            metadata: $response->metadata,
        );
    }

    /**
     * @param  list<AiChatMessage>  $messages
     */
    private function lastUserMessage(array $messages): ?AiChatMessage
    {
        foreach (array_reverse($messages) as $message) {
            if (
                $message instanceof AiChatMessage
                && $message->role === 'user'
                && ! $message->isToolMessage()
                && trim($message->content) !== ''
            ) {
                return new AiChatMessage('user', trim($message->content));
            }
        }

        return null;
    }
}
