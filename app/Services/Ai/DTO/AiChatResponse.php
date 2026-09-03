<?php

namespace App\Services\Ai\DTO;

final readonly class AiChatResponse
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $toolCalls
     */
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public ?string $finishReason = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
        public array $metadata = [],
        public array $toolCalls = [],
    ) {}
}
