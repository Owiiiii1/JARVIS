<?php

namespace App\Services\Ai\DTO;

final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $providerExtras
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public array $providerExtras = [],
    ) {}
}
