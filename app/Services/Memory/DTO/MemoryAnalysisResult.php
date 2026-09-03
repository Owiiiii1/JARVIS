<?php

namespace App\Services\Memory\DTO;

final readonly class MemoryAnalysisResult
{
    /**
     * @param  list<TopicCandidate>  $topics
     * @param  list<MemoryCandidate>  $memories
     */
    public function __construct(
        public array $topics = [],
        public array $memories = [],
        public ?string $profileCandidate = null,
    ) {}
}
