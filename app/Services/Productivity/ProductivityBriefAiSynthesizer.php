<?php

namespace App\Services\Productivity;

use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use Throwable;

final class ProductivityBriefAiSynthesizer implements SynthesizesProductivityBrief
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly AiConfigurationResolver $roles,
    ) {}

    public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
    {
        try {
            $configuration = $this->roles->resolveConversation($user);
            $payload = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: 'You rewrite a source-grounded Jarvis productivity brief. Keep every listed fact. Do not invent news, memories, or extra tasks. Write concise Russian, max 180 words. No markdown headings.',
                messages: [
                    new AiChatMessage('user', "Mode: {$mode}\n\nDeterministic brief:\n{$deterministic}\n\nSources JSON:\n{$payload}"),
                ],
                parameters: [
                    'max_tokens' => 400,
                    'temperature' => 0.2,
                ],
            ));
        } catch (Throwable) {
            return null;
        }

        $text = trim($response->text);

        return $text === '' ? null : $text;
    }
}
