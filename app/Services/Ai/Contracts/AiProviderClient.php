<?php

namespace App\Services\Ai\Contracts;

interface AiProviderClient
{
    public function provider(): string;

    public function label(): string;

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listModels(string $apiKey): array;
}
