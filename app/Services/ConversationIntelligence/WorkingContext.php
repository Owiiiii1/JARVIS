<?php

namespace App\Services\ConversationIntelligence;

use App\Enums\ReferenceOutcome;
use App\Enums\TopicContinuityMode;

final readonly class WorkingContext
{
    /**
     * @param  list<ConversationalEntity>  $recentEntities
     * @param  list<ConversationalEntity>  $recentToolReferences
     */
    public function __construct(
        public TopicContinuityMode $topicMode,
        public ReferenceOutcome $referenceOutcome,
        public string $continuitySource,
        public ?string $currentTopic = null,
        public ?string $previousTopic = null,
        public array $recentEntities = [],
        public array $recentToolReferences = [],
        public ?string $recentIntent = null,
        public ?string $pendingClarification = null,
        public ?ConversationalEntity $lastImportantObject = null,
        public ?string $activeProject = null,
        public ?string $temporaryStyle = null,
        public bool $incompleteUtterance = false,
        public ?string $clarificationReason = null,
        public bool $available = true,
    ) {}

    public static function unavailable(): self
    {
        return new self(
            topicMode: TopicContinuityMode::Continue,
            referenceOutcome: ReferenceOutcome::None,
            continuitySource: 'fallback',
            available: false,
        );
    }

    public function uniqueTrustedReport(): ?ConversationalEntity
    {
        $reports = [];

        foreach ($this->recentToolReferences as $entity) {
            if ($entity->type !== 'scheduled_report' || $entity->id === null || $entity->expired || ! $entity->trusted) {
                continue;
            }

            $reports[$entity->id] = $entity;
        }

        if (count($reports) !== 1) {
            return null;
        }

        return array_values($reports)[0];
    }

    public function uniqueTrustedTask(): ?ConversationalEntity
    {
        $tasks = [];

        foreach ($this->recentToolReferences as $entity) {
            if ($entity->type !== 'task' || $entity->id === null || $entity->expired || ! $entity->trusted) {
                continue;
            }

            $tasks[$entity->id] = $entity;
        }

        if (count($tasks) !== 1) {
            return null;
        }

        return array_values($tasks)[0];
    }

    /**
     * “Эта задача” after a reminder on the parent, when a subtask is also in recent tools.
     */
    public function referredTask(): ?ConversationalEntity
    {
        $unique = $this->uniqueTrustedTask();

        if ($unique !== null) {
            return $unique;
        }

        $last = $this->lastImportantObject;

        if (
            $last instanceof ConversationalEntity
            && $last->type === 'task'
            && $last->id !== null
            && $last->trusted
            && ! $last->expired
        ) {
            return $last;
        }

        return null;
    }

    public function trustsTaskId(int $id): bool
    {
        foreach ($this->recentToolReferences as $entity) {
            if ($entity->type === 'task' && $entity->id === $id && $entity->trusted && ! $entity->expired) {
                return true;
            }
        }

        return false;
    }

    public function allowsTrustedMutation(): bool
    {
        return $this->available
            && in_array($this->topicMode, [TopicContinuityMode::Continue, TopicContinuityMode::Subtopic], true)
            && $this->clarificationReason === null;
    }

    public function promptBlock(): ?string
    {
        if (! $this->available) {
            return null;
        }

        $lines = [
            'Conversational working context (temporary, this chat only; not permanent memory):',
            'Topic mode: '.$this->topicMode->value,
        ];

        if ($this->currentTopic !== null && $this->currentTopic !== '') {
            $lines[] = 'Current topic: '.$this->currentTopic;
        }

        if ($this->previousTopic !== null && $this->previousTopic !== '') {
            $lines[] = 'Previous topic: '.$this->previousTopic;
        }

        if ($this->activeProject !== null && $this->activeProject !== '') {
            $lines[] = 'Active project (if indicated): '.$this->activeProject;
        }

        if ($this->recentIntent !== null && $this->recentIntent !== '') {
            $lines[] = 'Recent user intent: '.$this->recentIntent;
        }

        if ($this->lastImportantObject !== null) {
            $lines[] = 'Last important object: '.$this->lastImportantObject->compactLine();
        }

        $entityLines = array_map(
            static fn (ConversationalEntity $entity): string => '- '.$entity->compactLine(),
            array_slice($this->recentEntities, 0, 8),
        );

        if ($entityLines !== []) {
            $lines[] = 'Recent entities:';
            $lines = array_merge($lines, $entityLines);
        }

        $toolLines = [];

        foreach (array_slice($this->recentToolReferences, 0, 6) as $entity) {
            $toolLines[] = '- '.$entity->compactLine();
        }

        if ($toolLines !== []) {
            $lines[] = 'Trusted recent tool results (use these ids; never invent ids):';
            $lines = array_merge($lines, $toolLines);
        }

        if ($this->incompleteUtterance) {
            $lines[] = 'Current utterance looks incomplete. Continue from immediate context if meaning is reasonably clear.';
        }

        if ($this->pendingClarification !== null && $this->pendingClarification !== '') {
            $lines[] = 'Pending clarification: '.$this->pendingClarification;
        }

        if ($this->temporaryStyle !== null && $this->temporaryStyle !== '') {
            $lines[] = 'Temporary style for this conversation only (do not write to the assistant profile): '.$this->temporaryStyle;
        }

        if ($this->topicMode === TopicContinuityMode::Return && $this->currentTopic !== null) {
            $lines[] = 'User is returning to a previous topic. Use topics, the current conversation summary, and search_conversation_history if a specific older detail is missing.';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(int $workingContextTokens = 0): array
    {
        return [
            'continuity_source' => $this->continuitySource,
            'topic_mode' => $this->topicMode->value,
            'reference_outcome' => $this->referenceOutcome->value,
            'clarification_reason' => $this->clarificationReason,
            'working_context_tokens' => $workingContextTokens,
            'working_context_available' => $this->available,
        ];
    }
}
