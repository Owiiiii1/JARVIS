<?php

namespace App\Services\Groups\DTO;

final readonly class GroupEventCandidate
{
    /**
     * @param  list<int>  $sourceMessageIds
     */
    public function __construct(
        public string $content,
        public float $confidence,
        public array $sourceMessageIds,
        public ?string $occurredAtLocal = null,
        public ?string $supersedesNormalizedKey = null,
        public ?int $threadId = null,
    ) {}
}
