<?php

namespace App\Services\Knowledge\DTO;

use App\Enums\KnowledgeSourceType;
use Carbon\CarbonImmutable;

final readonly class KnowledgeSourceRef
{
    public function __construct(
        public KnowledgeSourceType $type,
        public string $fingerprint,
        public float $confidence = 0.95,
        public ?int $conversationId = null,
        public ?int $messageId = null,
        public ?int $memoryId = null,
        public ?int $projectId = null,
        public ?int $taskId = null,
        public ?int $reminderId = null,
        public ?int $storedFileId = null,
        public bool $manual = false,
        public ?CarbonImmutable $observedAt = null,
    ) {}

    public static function hash(string ...$parts): string
    {
        return hash('sha256', implode('|', array_map(static fn (string $part): string => trim($part), $parts)));
    }
}
