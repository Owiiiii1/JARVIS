<?php

namespace App\Services\Ai\DTO;

final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $callId,
        public string $name,
        public bool $success,
        public array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(string $callId, string $name, array $payload): self
    {
        return new self($callId, $name, true, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function failure(string $callId, string $name, array $payload): self
    {
        return new self($callId, $name, false, $payload);
    }
}
