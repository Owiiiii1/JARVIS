<?php

namespace App\Services\Memory\DTO;

final readonly class TopicCandidate
{
    /**
     * @param  list<int>  $messageIds
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public float $confidence = 0.8,
        public array $messageIds = [],
    ) {}
}
