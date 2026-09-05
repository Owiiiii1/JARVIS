<?php

namespace Tests\Unit;

use App\Models\AiRoleSetting;
use App\Services\Ai\AiSafetyResponseService;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use PHPUnit\Framework\TestCase;

class AiSafetyResponseServiceTest extends TestCase
{
    public function test_retry_asks_for_contextual_safe_answer_without_tools_or_old_history(): void
    {
        $gateway = new RecordingSafetyGateway;
        $configuration = new AiRoleSetting;
        $configuration->forceFill([
            'provider' => 'gemini',
            'model' => 'gemini-test',
        ]);

        $response = (new AiSafetyResponseService($gateway))->retry(
            $configuration,
            [
                new AiChatMessage('user', 'Старый вопрос'),
                new AiChatMessage('assistant', 'Старый ответ'),
                new AiChatMessage('user', 'Как поступить безопасно?'),
            ],
            ['temperature' => 0.3],
        );

        $this->assertSame('Полезный безопасный ответ.', $response->text);
        $this->assertSame('safety_retry', $response->finishReason);
        $this->assertCount(1, $gateway->request?->messages ?? []);
        $this->assertSame('Как поступить безопасно?', $gateway->request?->messages[0]->content);
        $this->assertSame([], $gateway->request?->tools);
        $this->assertStringContainsString('useful', $gateway->request?->systemPrompt ?? '');
        $this->assertStringNotContainsString('policy', mb_strtolower($response->text));
    }
}

final class RecordingSafetyGateway implements AiChatGateway
{
    public ?AiChatRequest $request = null;

    public function chat(AiRoleSetting $configuration, AiChatRequest $request): AiChatResponse
    {
        $this->request = $request;

        return new AiChatResponse(
            text: 'Полезный безопасный ответ.',
            provider: 'gemini',
            model: (string) $configuration->model,
        );
    }

    public function supportsTools(AiRoleSetting $configuration): bool
    {
        return true;
    }

    public function supportsVision(AiRoleSetting $configuration): bool
    {
        return true;
    }
}
