<?php

namespace Tests\Unit;

use App\Services\Ai\Clients\AnthropicClient;
use App\Services\Ai\Clients\GeminiClient;
use App\Services\Ai\Clients\OpenAiClient;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderChatClientTest extends TestCase
{
    public function test_openai_chat_uses_responses_api_without_live_request(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test',
                'model' => 'gpt-test',
                'status' => 'completed',
                'output_text' => 'Hello from OpenAI',
                'usage' => [
                    'input_tokens' => 4,
                    'output_tokens' => 6,
                    'total_tokens' => 10,
                ],
            ], 200),
        ]);

        $response = (new OpenAiClient)->chat('sk-test', $this->request('gpt-test'));

        $this->assertSame('Hello from OpenAI', $response->text);
        $this->assertSame('openai', $response->provider);
        $this->assertSame(10, $response->totalTokens);

        Http::assertSent(function ($httpRequest): bool {
            return $httpRequest->url() === 'https://api.openai.com/v1/responses'
                && ($httpRequest['store'] ?? null) === false
                && ! str_contains(json_encode($httpRequest->data()), 'sk-test');
        });
    }

    public function test_anthropic_and_gemini_chat_use_faked_http(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'id' => 'msg_test',
                'model' => 'claude-test',
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Hello from Claude']],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 5],
            ], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Hello from Gemini']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 2,
                    'candidatesTokenCount' => 4,
                    'totalTokenCount' => 6,
                ],
            ], 200),
        ]);

        $anthropic = (new AnthropicClient)->chat('sk-ant-test', $this->request('claude-test'));
        $gemini = (new GeminiClient)->chat('gemini-key', $this->request('gemini-test'));

        $this->assertSame('Hello from Claude', $anthropic->text);
        $this->assertSame('Hello from Gemini', $gemini->text);
        $this->assertSame(6, $gemini->totalTokens);
    }

    private function request(string $model): AiChatRequest
    {
        return new AiChatRequest(
            model: $model,
            systemPrompt: 'You are a test assistant.',
            messages: [new AiChatMessage('user', 'Hi')],
        );
    }
}
