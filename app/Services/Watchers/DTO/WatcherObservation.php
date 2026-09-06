<?php

namespace App\Services\Watchers\DTO;

use Carbon\CarbonImmutable;

final readonly class WatcherObservation
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $eventType,
        public string $fingerprint,
        public CarbonImmutable $occurredAt,
        public string $title,
        public array $metadata = [],
        public ?int $entityId = null,
        public ?int $projectId = null,
        public ?int $taskId = null,
        public ?int $knowledgeEventId = null,
    ) {}
}
