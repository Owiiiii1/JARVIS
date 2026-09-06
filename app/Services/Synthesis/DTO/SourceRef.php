<?php

namespace App\Services\Synthesis\DTO;

final readonly class SourceRef
{
    public function __construct(
        public ?int $projectId = null,
        public ?int $taskId = null,
        public ?int $reminderId = null,
        public ?int $watcherId = null,
        public ?int $occurrenceId = null,
        public ?int $knowledgeEventId = null,
        public ?int $entityId = null,
        public ?int $conversationId = null,
        public ?int $messageId = null,
        public ?string $externalRef = null,
        public ?string $sourceFingerprint = null,
        public ?string $domain = null,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'reminder_id' => $this->reminderId,
            'watcher_id' => $this->watcherId,
            'occurrence_id' => $this->occurrenceId,
            'knowledge_event_id' => $this->knowledgeEventId,
            'entity_id' => $this->entityId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'external_ref' => $this->externalRef,
            'source_fingerprint' => $this->sourceFingerprint,
            'domain' => $this->domain,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
