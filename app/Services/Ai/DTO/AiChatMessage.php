<?php

namespace App\Services\Ai\DTO;

final readonly class AiChatMessage
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}

    /**
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }
}
