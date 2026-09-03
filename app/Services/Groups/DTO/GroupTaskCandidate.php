<?php

namespace App\Services\Groups\DTO;

final readonly class GroupTaskCandidate
{
    /**
     * @param  list<int>  $sourceMessageIds
     */
    public function __construct(
        public string $content,
        public float $confidence,
        public array $sourceMessageIds,
        public ?string $assigneeText = null,
        public ?string $dueAtLocal = null,
        public ?string $statusHint = null,
        public ?string $supersedesNormalizedKey = null,
        public ?int $threadId = null,
    ) {}
}
