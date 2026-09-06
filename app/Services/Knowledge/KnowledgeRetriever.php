<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeRelationStatus;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationIntelligence\WorkingContext;
use App\Services\Knowledge\Exceptions\KnowledgeException;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class KnowledgeRetriever
{
    public function __construct(
        private readonly KnowledgeEntityResolver $resolver,
        private readonly KnowledgeIngestionService $ingestion,
    ) {}

    public function contextBlock(User $user, ?WorkingContext $working, ?string $userTurn = null): ?string
    {
        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return null;
        }

        $entities = $this->contextEntities($user, $working, $userTurn);

        if ($entities === []) {
            return null;
        }

        $maxRelations = max(1, (int) config('knowledge.retrieval.max_relations_per_entity', 5));
        $maxEvents = max(1, (int) config('knowledge.retrieval.max_events_per_entity', 5));
        $lines = ['Structured knowledge (index only; not a full graph; prefer tools for detail):'];

        foreach ($entities as $entity) {
            $lines[] = $this->entityLine($entity);
            $relations = $this->relationshipsFor($user, $entity, $maxRelations);

            foreach ($relations as $relation) {
                $other = (int) $relation->source_entity_id === (int) $entity->id
                    ? $relation->targetEntity
                    : $relation->sourceEntity;
                $direction = (int) $relation->source_entity_id === (int) $entity->id ? '→' : '←';
                $lines[] = '  '.$direction.' '.$relation->type->value.' '.($other?->name ?? '#'.$relation->target_entity_id);
            }

            $events = $this->timelineFor($user, $entity, $maxEvents);

            foreach ($events as $event) {
                $when = $event->occurred_at instanceof CarbonImmutable
                    ? $event->occurred_at->toDateString()
                    : '';
                $lines[] = '  '.$when.' '.$event->type->value.': '.$event->title;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(User $user, string $query, ?string $type = null, ?int $projectId = null, int $limit = 8): array
    {
        $this->assertKnowledge($user);
        $entityType = $type !== null && $type !== '' ? KnowledgeEntityType::tryFromLoose($type) : null;
        $rows = $this->resolver->search($user, $query, $entityType, $limit);

        if ($projectId !== null) {
            $rows = $rows->filter(
                static fn (KnowledgeEntity $entity): bool => (int) $entity->project_id === $projectId,
            )->values();
        }

        return $rows->map(fn (KnowledgeEntity $entity): array => $this->compactEntity($entity, includeExtras: false))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getEntity(User $user, int $entityId): array
    {
        $this->assertKnowledge($user);
        $entity = $this->ingestion->requireOwnedEntity($user, $entityId);
        $entity->loadCount(['aliases', 'sources']);
        $entity->load(['aliases:id,knowledge_entity_id,alias', 'project:id,name,status']);

        $payload = $this->compactEntity($entity, includeExtras: true);
        $payload['relationships'] = $this->relationshipsFor($user, $entity, 5)
            ->map(fn (KnowledgeRelationship $relation): array => $this->compactRelationship($entity, $relation))
            ->all();
        $payload['timeline'] = $this->timelineFor($user, $entity, 5)
            ->map(fn (KnowledgeEvent $event): array => $this->compactEvent($event))
            ->all();

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(User $user, int $entityId, int $limit = 8, ?CarbonImmutable $since = null): array
    {
        $this->assertKnowledge($user);
        $entity = $this->ingestion->requireOwnedEntity($user, $entityId);

        return $this->timelineFor($user, $entity, $limit, $since)
            ->map(fn (KnowledgeEvent $event): array => $this->compactEvent($event))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function relationships(User $user, int $entityId, int $limit = 8): array
    {
        $this->assertKnowledge($user);
        $entity = $this->ingestion->requireOwnedEntity($user, $entityId);

        return $this->relationshipsFor($user, $entity, $limit)
            ->map(fn (KnowledgeRelationship $relation): array => $this->compactRelationship($entity, $relation))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function related(User $user, int $entityId, int $limit = 8): array
    {
        $this->assertKnowledge($user);
        $entity = $this->ingestion->requireOwnedEntity($user, $entityId);
        $related = [];

        foreach ($this->relationshipsFor($user, $entity, $limit) as $relation) {
            $otherId = (int) $relation->source_entity_id === (int) $entity->id
                ? (int) $relation->target_entity_id
                : (int) $relation->source_entity_id;

            if (isset($related[$otherId])) {
                continue;
            }

            $other = (int) $relation->source_entity_id === (int) $entity->id
                ? $relation->targetEntity
                : $relation->sourceEntity;

            if ($other === null || (int) $other->user_id !== (int) $user->id) {
                continue;
            }

            $related[$otherId] = $this->compactEntity($other, includeExtras: false);
        }

        return array_values($related);
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceIndex(User $user, ?string $query = null, ?string $type = null): array
    {
        $this->assertKnowledge($user);

        $people = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('type', KnowledgeEntityType::Person)
            ->where('status', KnowledgeEntityStatus::Active)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (KnowledgeEntity $entity): array => $this->compactEntity($entity, includeExtras: false))
            ->all();

        $projects = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('type', KnowledgeEntityType::Project)
            ->where('status', KnowledgeEntityStatus::Active)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (KnowledgeEntity $entity): array => $this->compactEntity($entity, includeExtras: false))
            ->all();

        $activity = KnowledgeEvent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (KnowledgeEvent $event): array => $this->compactEvent($event))
            ->all();

        $results = [];

        if ($query !== null && trim($query) !== '') {
            $results = $this->search($user, $query, $type);
        }

        return [
            'people' => $people,
            'projects' => $projects,
            'recent_activity' => $activity,
            'results' => $results,
            'counts' => [
                'entities' => KnowledgeEntity::query()->where('user_id', $user->id)->count(),
                'people' => KnowledgeEntity::query()->where('user_id', $user->id)->where('type', KnowledgeEntityType::Person)->count(),
                'projects' => KnowledgeEntity::query()->where('user_id', $user->id)->where('type', KnowledgeEntityType::Project)->count(),
            ],
        ];
    }

    /**
     * @return list<KnowledgeEntity>
     */
    private function contextEntities(User $user, ?WorkingContext $working, ?string $userTurn): array
    {
        $limit = max(1, (int) config('knowledge.retrieval.max_entities', 3));
        $minConfidence = (float) config('knowledge.retrieval.min_confidence', 0.8);
        $candidates = [];

        $activeProject = $working?->activeProject;

        if ($activeProject !== null && $activeProject !== '') {
            $match = $this->resolver->findByNormalizedName($user, KnowledgeNameNormalizer::name($activeProject));

            if ($match !== null && (float) $match->confidence >= $minConfidence) {
                $candidates[$match->id] = $match;
            } else {
                $project = Project::query()
                    ->where('user_id', $user->id)
                    ->where('normalized_name', KnowledgeNameNormalizer::name($activeProject))
                    ->first();

                if ($project !== null) {
                    $linked = KnowledgeEntity::query()
                        ->where('user_id', $user->id)
                        ->where('project_id', $project->id)
                        ->where('type', KnowledgeEntityType::Project)
                        ->first();

                    if ($linked !== null && (float) $linked->confidence >= $minConfidence) {
                        $candidates[$linked->id] = $linked;
                    }
                }
            }
        }

        $labels = [];

        foreach ($working?->recentEntities ?? [] as $entity) {
            if ($entity->label !== '') {
                $labels[] = $entity->label;
            }
        }

        if ($userTurn !== null && trim($userTurn) !== '') {
            $labels[] = $userTurn;
        }

        foreach ($labels as $label) {
            foreach ($this->resolver->search($user, $label, null, $limit) as $match) {
                if ((float) $match->confidence < $minConfidence) {
                    continue;
                }

                $candidates[$match->id] = $match;
            }
        }

        return array_slice(array_values($candidates), 0, $limit);
    }

    private function relationshipsFor(User $user, KnowledgeEntity $entity, int $limit): Collection
    {
        return KnowledgeRelationship::query()
            ->where('user_id', $user->id)
            ->where('status', KnowledgeRelationStatus::Active)
            ->where(function ($query) use ($entity): void {
                $query->where('source_entity_id', $entity->id)
                    ->orWhere('target_entity_id', $entity->id);
            })
            ->with(['sourceEntity:id,user_id,type,name,normalized_name', 'targetEntity:id,user_id,type,name,normalized_name'])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function timelineFor(User $user, KnowledgeEntity $entity, int $limit, ?CarbonImmutable $since = null): Collection
    {
        $query = KnowledgeEvent::query()
            ->where('user_id', $user->id)
            ->whereHas('entities', function ($inner) use ($entity): void {
                $inner->where('knowledge_entities.id', $entity->id);
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('occurred_at', '>=', $since);
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function compactEntity(KnowledgeEntity $entity, bool $includeExtras): array
    {
        $payload = [
            'id' => $entity->id,
            'type' => $entity->type->value,
            'name' => $entity->name,
            'summary' => $entity->summary,
            'status' => $entity->status->value,
            'project_id' => $entity->project_id,
            'confidence' => $entity->confidence,
        ];

        if ($includeExtras) {
            $payload['aliases'] = $entity->aliases->pluck('alias')->take(8)->values()->all();
            $payload['sources_count'] = (int) ($entity->sources_count ?? $entity->sources()->count());
            $payload['project'] = $entity->project !== null
                ? [
                    'id' => $entity->project->id,
                    'name' => $entity->project->name,
                    'status' => $entity->project->status->value,
                ]
                : null;
            $payload['open_tasks_count'] = $entity->project_id !== null
                ? Task::query()->where('project_id', $entity->project_id)->where('user_id', $entity->user_id)->whereIn('status', ['open', 'in_progress'])->count()
                : 0;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function compactRelationship(KnowledgeEntity $entity, KnowledgeRelationship $relation): array
    {
        $other = (int) $relation->source_entity_id === (int) $entity->id
            ? $relation->targetEntity
            : $relation->sourceEntity;

        return [
            'id' => $relation->id,
            'type' => $relation->type->value,
            'label' => $relation->label,
            'status' => $relation->status->value,
            'direction' => (int) $relation->source_entity_id === (int) $entity->id ? 'outgoing' : 'incoming',
            'other' => $other !== null ? [
                'id' => $other->id,
                'type' => $other->type->value,
                'name' => $other->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactEvent(KnowledgeEvent $event): array
    {
        $metadata = $event->metadata ?? [];

        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'title' => $event->title,
            'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
            'source_type' => $event->source_type->value,
            'refs' => array_filter([
                'conversation_id' => $event->conversation_id ?? ($metadata['conversation_id'] ?? null),
                'task_id' => $metadata['task_id'] ?? null,
                'project_id' => $metadata['project_id'] ?? null,
                'stored_file_id' => $metadata['stored_file_id'] ?? null,
            ], static fn ($value): bool => $value !== null),
        ];
    }

    private function entityLine(KnowledgeEntity $entity): string
    {
        $bits = [$entity->type->value, $entity->name];

        if ($entity->summary) {
            $bits[] = '— '.$entity->summary;
        }

        return implode(' ', $bits);
    }

    private function assertKnowledge(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            throw new KnowledgeException('capability_denied', 'Knowledge is not available.');
        }
    }
}
