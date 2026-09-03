<?php

namespace App\Services\Ai\DTO;

final readonly class AiChatRequest
{
    /**
     * @param  list<AiChatMessage>  $messages
     * @param  array<string, mixed>  $parameters
     * @param  list<ToolDefinition>  $tools
     */
    public function __construct(
        public string $model,
        public string $systemPrompt,
        public array $messages,
        public array $parameters = [],
        public array $tools = [],
    ) {}

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }

    public function temperature(): ?float
    {
        if (! array_key_exists('temperature', $this->parameters) || $this->parameters['temperature'] === null) {
            return null;
        }

        return (float) $this->parameters['temperature'];
    }

    public function maxTokens(): ?int
    {
        if (! array_key_exists('max_tokens', $this->parameters) || $this->parameters['max_tokens'] === null) {
            return null;
        }

        return (int) $this->parameters['max_tokens'];
    }
}
