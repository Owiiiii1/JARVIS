<?php

namespace App\Services\Synthesis;

use App\Enums\ConversationSummaryStatus;
use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationStatus;
use App\Enums\ProjectStatus;
use App\Enums\SynthesisType;
use App\Models\ConversationSummary;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\Exceptions\SynthesisException;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;

final class SynthesisFactCollector
{
    public function __construct(
        private readonly SynthesisClock $clock = new SynthesisClock,
    ) {}

    public function collect(SynthesisScope $scope): FactPack
    {
        $user = $scope->user;
        $now = $scope->now();
        $timezone = $this->clock->timezone($user);
        $local = $this->clock->local($now, $timezone);
        [$windowStart, $windowEnd] = $this->window($scope, $local, $now);
        $limits = config('synthesis.factpack', []);

        $project = $this->resolveProject($scope, $user);
        $entity = $this->resolveEntity($scope, $user, $project);

        if ($scope->type === SynthesisType::PersonStatus && $entity === null) {
            throw new SynthesisException('not_found', 'Person entity was not found.');
        }

        if ($scope->type === SynthesisType::ProjectStatus && $project === null && $entity === null) {
            throw new SynthesisException('not_found', 'Project was not found.');
        }

        $projectIds = $this->projectIds($user, $project, (int) ($limits['max_projects'] ?? 5));
        $entityIds = $this->scopedEntityIds($user, $entity, $project, $projectIds, (int) ($limits['max_people'] ?? 10));

        $tasks = $this->tasks($user, $project, $entity, (int) ($limits['max_tasks'] ?? 30));
        $reminders = $this->reminders($user, $project, (int) ($limits['max_reminders'] ?? 20), $now);
        $watchers = $this->watchers($user, $project, $entity, (int) ($limits['max_watchers'] ?? 20));
        $occurrences = $this->occurrences($user, $watchers, $windowStart, (int) ($limits['max_events'] ?? 30));
        $events = $this->events($user, $entityIds, $windowStart, (int) ($limits['max_events'] ?? 30));
        $relationships = $this->relationships($user, $entityIds);
        $people = $this->people($user, $entityIds, $entity, (int) ($limits['max_people'] ?? 10));
        $summaries = $this->summaries($user, $project, $windowStart, (int) ($limits['max_summaries'] ?? 8));
        $projects = $project !== null
            ? [$project]
            : $this->projects($user, $projectIds);

        $entities = [];

        if ($entity !== null) {
            $entities[] = $entity;
        }

        foreach ($people as $person) {
            $entities[] = $person;
        }

        return new FactPack(
            type: $scope->type,
            now: $now,
            timezone: $timezone,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            projects: $projects,
            people: $people,
            entities: $entities,
            tasks: $tasks,
            reminders: $reminders,
            watchers: $watchers,
            occurrences: $occurrences,
            events: $events,
            relationships: $relationships,
            summaries: $summaries,
            project: $project,
            entity: $entity,
            freshness: $this->freshness($events, $watchers, $now),
            userId: (int) $scope->user->id,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(SynthesisScope $scope, CarbonImmutable $local, CarbonImmutable $now): array
    {
        if ($scope->type === SynthesisType::DailyDigest) {
            $start = $local->startOfDay()->utc();

            return [$start, $local->endOfDay()->utc()];
        }

        if ($scope->type === SynthesisType::WeeklyDigest) {
            $start = $this->clock->weekStart($local)->utc();

            return [$start, $now];
        }

        return [$now->subDays($scope->windowDays), $now];
    }

    private function resolveProject(SynthesisScope $scope, User $user): ?Project
    {
        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return null;
        }

        if ($scope->projectId !== null) {
            $project = Project::query()
                ->where('user_id', $user->id)
                ->whereKey($scope->projectId)
                ->first();

            if ($project === null) {
                throw new SynthesisException('not_found', 'Project was not found.');
            }

            return $project;
        }

        $name = trim((string) $scope->projectName);

        if ($name === '') {
            return null;
        }

        $match = Project::query()
            ->where('user_id', $user->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($match === null) {
            $match = Project::query()
                ->where('user_id', $user->id)
                ->where('name', 'like', '%'.$name.'%')
                ->orderBy('id')
                ->first();
        }

        return $match;
    }

    private function resolveEntity(SynthesisScope $scope, User $user, ?Project $project): ?KnowledgeEntity
    {
        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return null;
        }

        if ($scope->entityId !== null) {
            $entity = KnowledgeEntity::query()
                ->where('user_id', $user->id)
                ->whereKey($scope->entityId)
                ->first();

            if ($entity === null) {
                throw new SynthesisException('not_found', 'Entity was not found.');
            }

            return $entity;
        }

        $name = trim((string) ($scope->personName ?? $scope->projectName));

        if ($name === '' && $project === null) {
            return null;
        }

        $query = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('status', KnowledgeEntityStatus::Active);

        if ($scope->type === SynthesisType::PersonStatus) {
            $query->where('type', KnowledgeEntityType::Person);
        }

        if ($name !== '') {
            $query->where(function ($inner) use ($name): void {
                $inner->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->orWhere('name', 'like', '%'.$name.'%');
            });
        } elseif ($project !== null) {
            $query->where('project_id', $project->id)->where('type', KnowledgeEntityType::Project);
        }

        return $query->orderBy('id')->first();
    }

    /**
     * @return list<int>
     */
    private function projectIds(User $user, ?Project $project, int $limit): array
    {
        if ($project !== null) {
            return [(int) $project->id];
        }

        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return [];
        }

        return Project::query()
            ->where('user_id', $user->id)
            ->where('status', ProjectStatus::Active)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    private function scopedEntityIds(User $user, ?KnowledgeEntity $entity, ?Project $project, array $projectIds, int $limit): array
    {
        if ($entity !== null) {
            $ids = [(int) $entity->id];
            $related = KnowledgeRelationship::query()
                ->where('user_id', $user->id)
                ->where('status', KnowledgeRelationStatus::Active)
                ->where(function ($query) use ($entity): void {
                    $query->where('source_entity_id', $entity->id)
                        ->orWhere('target_entity_id', $entity->id);
                })
                ->limit(20)
                ->get(['source_entity_id', 'target_entity_id']);

            foreach ($related as $relation) {
                $ids[] = (int) $relation->source_entity_id;
                $ids[] = (int) $relation->target_entity_id;
            }

            return array_values(array_unique($ids));
        }

        $query = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('status', KnowledgeEntityStatus::Active)
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($project !== null) {
            $query->where('project_id', $project->id);
        } elseif ($projectIds !== []) {
            $query->where(function ($inner) use ($projectIds): void {
                $inner->whereIn('project_id', $projectIds)->orWhereNull('project_id');
            });
        }

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @return list<Task>
     */
    private function tasks(User $user, ?Project $project, ?KnowledgeEntity $entity, int $limit): array
    {
        if (! $user->canUseCapability(UserCapability::TASKS)) {
            return [];
        }

        $query = Task::query()
            ->where('user_id', $user->id)
            ->with(['parent'])
            ->orderByRaw("CASE WHEN status IN ('open','in_progress') THEN 0 ELSE 1 END")
            ->orderBy('due_at')
            ->limit($limit);

        if ($project !== null) {
            $query->where('project_id', $project->id);
        } elseif ($entity?->project_id) {
            $query->where('project_id', $entity->project_id);
        }

        return $query->get()->all();
    }

    /**
     * @return list<Reminder>
     */
    private function reminders(User $user, ?Project $project, int $limit, CarbonImmutable $now): array
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            return [];
        }

        $query = Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->where('run_at', '>=', $now->subDay())
            ->orderBy('run_at')
            ->limit($limit);

        if ($project !== null) {
            $query->whereHas('task', function ($task) use ($project): void {
                $task->where('project_id', $project->id);
            });
        }

        return $query->get()->all();
    }

    /**
     * @return list<Watcher>
     */
    private function watchers(User $user, ?Project $project, ?KnowledgeEntity $entity, int $limit): array
    {
        if (! $user->canUseCapability(UserCapability::WATCHERS)) {
            return [];
        }

        $query = Watcher::query()
            ->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($project !== null) {
            $query->where(function ($inner) use ($project): void {
                $inner->where('project_id', $project->id)->orWhereNull('project_id');
            });
        }

        if ($entity !== null) {
            $query->where(function ($inner) use ($entity): void {
                $inner->where('knowledge_entity_id', $entity->id)
                    ->orWhereNull('knowledge_entity_id');
            });
        }

        return $query->get()->all();
    }

    /**
     * @param  list<Watcher>  $watchers
     * @return list<WatcherOccurrence>
     */
    private function occurrences(User $user, array $watchers, CarbonImmutable $since, int $limit): array
    {
        $ids = array_map(static fn (Watcher $watcher): int => (int) $watcher->id, $watchers);

        if ($ids === []) {
            return [];
        }

        return WatcherOccurrence::query()
            ->where('user_id', $user->id)
            ->whereIn('watcher_id', $ids)
            ->where('detected_at', '>=', $since)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $entityIds
     * @return list<KnowledgeEvent>
     */
    private function events(User $user, array $entityIds, CarbonImmutable $since, int $limit): array
    {
        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return [];
        }

        $query = KnowledgeEvent::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', $since)
            ->with('entities')
            ->orderByDesc('occurred_at')
            ->limit($limit);

        if ($entityIds !== []) {
            $query->where(function ($inner) use ($entityIds): void {
                $inner->whereHas('entities', function ($entities) use ($entityIds): void {
                    $entities->whereIn('knowledge_entities.id', $entityIds);
                })->orWhereIn('type', [
                    KnowledgeEventType::CommitmentMade->value,
                    KnowledgeEventType::CommitmentFulfilled->value,
                    KnowledgeEventType::TaskCompleted->value,
                    KnowledgeEventType::TaskCreated->value,
                    KnowledgeEventType::ManualNote->value,
                    KnowledgeEventType::GithubCommitSeen->value,
                    KnowledgeEventType::EmailReceived->value,
                    KnowledgeEventType::CalendarEvent->value,
                ]);
            });
        }

        return $query->get()->all();
    }

    /**
     * @param  list<int>  $entityIds
     * @return list<KnowledgeRelationship>
     */
    private function relationships(User $user, array $entityIds): array
    {
        if ($entityIds === [] || ! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return [];
        }

        return KnowledgeRelationship::query()
            ->where('user_id', $user->id)
            ->where('status', KnowledgeRelationStatus::Active)
            ->where(function ($query) use ($entityIds): void {
                $query->whereIn('source_entity_id', $entityIds)
                    ->orWhereIn('target_entity_id', $entityIds);
            })
            ->with(['sourceEntity', 'targetEntity'])
            ->limit(40)
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $entityIds
     * @return list<KnowledgeEntity>
     */
    private function people(User $user, array $entityIds, ?KnowledgeEntity $focus, int $limit): array
    {
        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return [];
        }

        $query = KnowledgeEntity::query()
            ->where('user_id', $user->id)
            ->where('type', KnowledgeEntityType::Person)
            ->where('status', KnowledgeEntityStatus::Active)
            ->limit($limit);

        if ($focus?->type === KnowledgeEntityType::Person) {
            $query->whereKey($focus->id);
        } elseif ($entityIds !== []) {
            $query->whereIn('id', $entityIds);
        }

        return $query->get()->all();
    }

    /**
     * @return list<ConversationSummary>
     */
    private function summaries(User $user, ?Project $project, CarbonImmutable $since, int $limit): array
    {
        return ConversationSummary::query()
            ->where('user_id', $user->id)
            ->where('status', ConversationSummaryStatus::Current)
            ->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<Project>
     */
    private function projects(User $user, array $projectIds): array
    {
        if ($projectIds === [] || ! $user->canUseCapability(UserCapability::PROJECTS)) {
            return [];
        }

        return Project::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $projectIds)
            ->get()
            ->all();
    }

    /**
     * @param  list<KnowledgeEvent>  $events
     * @param  list<Watcher>  $watchers
     * @return array<string, mixed>
     */
    private function freshness(array $events, array $watchers, CarbonImmutable $now): array
    {
        $observed = [];

        foreach ($events as $event) {
            $source = $event->source_type->value;
            $at = $event->occurred_at;

            if ($at instanceof CarbonImmutable && (! isset($observed[$source]) || $at->greaterThan($observed[$source]))) {
                $observed[$source] = $at;
            }
        }

        foreach ($watchers as $watcher) {
            $source = $watcher->source_type->value;
            $at = $watcher->last_checked_at ?? $watcher->last_triggered_at;

            if ($at instanceof CarbonImmutable && (! isset($observed[$source]) || $at->greaterThan($observed[$source]))) {
                $observed[$source] = $at;
            }
        }

        $staleHours = max(1, (int) config('synthesis.stale_after_hours', 6));
        $notes = [];
        $overall = 'indexed';

        foreach (['github' => 'GitHub', 'gmail' => 'Gmail', 'calendar' => 'Calendar'] as $key => $label) {
            $at = $observed[$key] ?? null;

            if (! $at instanceof CarbonImmutable) {
                continue;
            }

            $ageHours = max(0, (int) $at->utc()->diffInHours($now->utc()));
            $stale = $at->utc()->addHours($staleHours)->lessThanOrEqualTo($now->utc());

            if ($stale) {
                $overall = 'stale';
            }

            $notes[$key] = [
                'last_observed_at' => $at->toIso8601String(),
                'stale' => $stale,
                'note' => $label.' data last observed '.$this->ageLabel($ageHours).($stale ? '. Use the live '.$label.' tool for current data.' : ''),
            ];
        }

        return [
            'status' => $overall,
            'generated_from' => 'indexed_domains',
            'integrations' => $notes,
        ];
    }

    private function ageLabel(int $hours): string
    {
        if ($hours < 1) {
            return 'just now';
        }

        if ($hours < 24) {
            return $hours.'h ago';
        }

        return intdiv($hours, 24).'d ago';
    }
}
