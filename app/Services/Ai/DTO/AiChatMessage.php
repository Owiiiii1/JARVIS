<?php

namespace App\Services\Ai\DTO;

final readonly class AiChatMessage
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<string, mixed>|null  $toolResponse
     */
    public function __construct(
        public string $role,
        public string $content = '',
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?array $toolResponse = null,
    ) {}

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role === 'tool' ? 'user' : $this->role,
            'content' => $this->content,
        ];
    }

    public function isToolMessage(): bool
    {
        return $this->role === 'tool' || $this->toolResponse !== null || $this->toolCalls !== [];
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public static function assistantToolCalls(array $toolCalls, string $text = ''): self
    {
        return new self(
            role: 'assistant',
            content: $text,
            toolCalls: $toolCalls,
        );
    }

    public static function toolResult(ToolResult $result): self
    {
        return new self(
            role: 'tool',
            content: json_encode($result->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            toolCallId: $result->callId,
            toolName: $result->name,
            toolResponse: $result->payload,
        );
    }
}
