<?php

namespace App\Services\Synthesis\DTO;

use App\Enums\SynthesisType;
use App\Models\ConversationSummary;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use Carbon\CarbonImmutable;

final class FactPack
{
    /**
     * @param  list<Project>  $projects
     * @param  list<KnowledgeEntity>  $people
     * @param  list<KnowledgeEntity>  $entities
     * @param  list<Task>  $tasks
     * @param  list<Reminder>  $reminders
     * @param  list<Watcher>  $watchers
     * @param  list<WatcherOccurrence>  $occurrences
     * @param  list<KnowledgeEvent>  $events
     * @param  list<KnowledgeRelationship>  $relationships
     * @param  list<ConversationSummary>  $summaries
     * @param  array<string, mixed>  $freshness
     */
    public function __construct(
        public SynthesisType $type,
        public CarbonImmutable $now,
        public string $timezone,
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
        public array $projects = [],
        public array $people = [],
        public array $entities = [],
        public array $tasks = [],
        public array $reminders = [],
        public array $watchers = [],
        public array $occurrences = [],
        public array $events = [],
        public array $relationships = [],
        public array $summaries = [],
        public ?Project $project = null,
        public ?KnowledgeEntity $entity = null,
        public array $freshness = [],
        public ?int $userId = null,
    ) {}
}
