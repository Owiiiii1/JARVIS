<?php

namespace App\Services\Groups\DTO;

final readonly class GroupDecisionCandidate
{
    /**
     * @param  list<int>  $sourceMessageIds
     * @param  list<string>  $participants
     */
    public function __construct(
        public string $content,
        public float $confidence,
        public array $sourceMessageIds,
        public array $participants = [],
        public ?string $effectiveDateLocal = null,
        public ?string $supersedesNormalizedKey = null,
        public ?int $threadId = null,
    ) {}
}
