<?php

namespace App\Services\Memory\DTO;

final readonly class MemoryCandidate
{
    /**
     * @param  list<int>  $sourceMessageIds
     */
    public function __construct(
        public string $kind,
        public string $content,
        public ?string $normalizedKey,
        public float $confidence,
        public string $action,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
        public ?string $supersedeNormalizedKey = null,
        public array $sourceMessageIds = [],
        public ?string $reason = null,
    ) {}
}
