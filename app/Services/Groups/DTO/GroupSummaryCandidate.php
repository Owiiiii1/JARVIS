<?php

namespace App\Services\Groups\DTO;

final readonly class GroupSummaryCandidate
{
    public function __construct(
        public string $content,
        public float $confidence,
        public array $sourceMessageIds,
    ) {}
}
