<?php

namespace App\Services\Ai\DTO;

final readonly class AiChatMessage
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<string, mixed>|null  $toolResponse
     * @param  list<array<string, mixed>>  $nativeParts
     * @param  list<AiContentPart>  $contentParts
     */
    public function __construct(
        public string $role,
        public string $content = '',
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?array $toolResponse = null,
        public array $nativeParts = [],
        public array $contentParts = [],
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

    public function hasImageParts(): bool
    {
        foreach ($this->contentParts as $part) {
            if ($part instanceof AiContentPart && $part->isImage()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<AiContentPart>  $parts
     */
    public static function fromContentParts(string $role, array $parts): self
    {
        $text = [];

        foreach ($parts as $part) {
            if ($part instanceof AiContentPart && $part->isText() && filled($part->text)) {
                $text[] = $part->text;
            }
        }

        return new self(
            role: $role,
            content: implode("\n", $text),
            contentParts: $parts,
        );
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<array<string, mixed>>  $nativeParts
     */
    public static function assistantToolCalls(array $toolCalls, string $text = '', array $nativeParts = []): self
    {
        return new self(
            role: 'assistant',
            content: $text,
            toolCalls: $toolCalls,
            nativeParts: $nativeParts,
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
