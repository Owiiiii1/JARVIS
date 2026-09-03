<?php

namespace Tests\Unit;

use App\Services\Ai\Clients\AnthropicClient;
use App\Services\Ai\Clients\GeminiClient;
use App\Services\Ai\Clients\OpenAiClient;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiProviderException;
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

    public function test_gemini_parses_function_call_and_sends_function_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'functionCall' => [
                                    'name' => 'create_reminder',
                                    'args' => [
                                        'text' => 'сходить в магазин',
                                        'run_at_local' => '2026-09-04T11:00:00+02:00',
                                    ],
                                ],
                            ]],
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => [
                        'promptTokenCount' => 8,
                        'candidatesTokenCount' => 3,
                        'totalTokenCount' => 11,
                    ],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'Хорошо, напомню завтра в 11:00.']]],
                        'finishReason' => 'STOP',
                    ]],
                ], 200),
        ]);

        $tools = [new ToolDefinition(
            name: 'create_reminder',
            description: 'Создаёт персональное напоминание пользователя с доставкой в Telegram.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'text' => ['type' => 'STRING'],
                    'run_at_local' => ['type' => 'STRING'],
                ],
                'required' => ['text', 'run_at_local'],
            ],
        )];

        $client = new GeminiClient;
        $first = $client->chat('gemini-key', new AiChatRequest(
            model: 'gemini-test',
            systemPrompt: 'You are Jarvis.',
            messages: [new AiChatMessage('user', 'Напомни завтра в 11')],
            tools: $tools,
        ));

        $this->assertSame('', $first->text);
        $this->assertCount(1, $first->toolCalls);
        $this->assertSame('create_reminder', $first->toolCalls[0]->name);
        $this->assertSame('сходить в магазин', $first->toolCalls[0]->arguments['text']);

        $result = ToolResult::success($first->toolCalls[0]->id, 'create_reminder', [
            'success' => true,
            'reminder_id' => 123,
            'text' => 'сходить в магазин',
            'run_at_local' => '2026-09-04T11:00:00+02:00',
            'timezone' => 'Europe/Rome',
        ]);

        $second = $client->chat('gemini-key', new AiChatRequest(
            model: 'gemini-test',
            systemPrompt: 'You are Jarvis.',
            messages: [
                new AiChatMessage('user', 'Напомни завтра в 11'),
                AiChatMessage::assistantToolCalls($first->toolCalls),
                AiChatMessage::toolResult($result),
            ],
            tools: $tools,
        ));

        $this->assertSame('Хорошо, напомню завтра в 11:00.', $second->text);
        $this->assertSame([], $second->toolCalls);

        Http::assertSent(function ($httpRequest): bool {
            $payload = json_encode($httpRequest->data()) ?: '';

            return str_contains($httpRequest->url(), 'generateContent')
                && str_contains($payload, 'functionDeclarations')
                && str_contains($payload, 'create_reminder')
                && ! str_contains($payload, 'functionResponse');
        });

        Http::assertSent(function ($httpRequest): bool {
            $payload = json_encode($httpRequest->data()) ?: '';

            return str_contains($payload, 'functionResponse')
                && str_contains($payload, 'reminder_id')
                && ! str_contains($payload, 'gemini-key');
        });
    }

    public function test_openai_and_anthropic_refuse_tool_requests(): void
    {
        $request = new AiChatRequest(
            model: 'test',
            systemPrompt: 'You are Jarvis.',
            messages: [new AiChatMessage('user', 'Hi')],
            tools: [new ToolDefinition('create_reminder', 'test', ['type' => 'OBJECT'])],
        );

        try {
            (new OpenAiClient)->chat('sk-test', $request);
            $this->fail('OpenAI should refuse tools.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('does not support tools', $exception->getMessage());
        }

        try {
            (new AnthropicClient)->chat('sk-ant-test', $request);
            $this->fail('Anthropic should refuse tools.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('does not support tools', $exception->getMessage());
        }
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
