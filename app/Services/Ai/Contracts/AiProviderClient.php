<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;

interface AiProviderClient
{
    public function provider(): string;

    public function label(): string;

    public function supportsChat(): bool;

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listModels(string $apiKey): array;

    public function chat(string $apiKey, AiChatRequest $request): AiChatResponse;
}
