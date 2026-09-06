<?php

namespace App\Services\Synthesis;

use App\Enums\KnowledgeRelationType;
use App\Enums\WatcherHealth;
use App\Enums\WatcherMode;
use App\Enums\WatcherStatus;
use App\Models\KnowledgeRelationship;
use App\Models\Task;
use App\Models\Watcher;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanSynthesisText;
use App\Services\Workspace\Presentation\HumanWatcherDescription;
use Carbon\CarbonImmutable;

final class WaitingForResolver
{
    public function __construct(
        private readonly CanonicalStateResolver $canonical = new CanonicalStateResolver,
    ) {}

    /**
     * @return list<SynthesisItem>
     */
    public function resolve(FactPack $pack): array
    {
        $items = [];
        $stale = $this->staleWatcherIds($pack->watchers);
        $tasks = $this->watcherTasks($pack->watchers);

        foreach ($pack->watchers as $watcher) {
            if (! $watcher instanceof Watcher || $watcher->status !== WatcherStatus::Active) {
                continue;
            }

            if (isset($stale[(int) $watcher->id])) {
                continue;
            }

            if ($watcher->mode !== WatcherMode::OneShot && $watcher->health !== WatcherHealth::Waiting) {
                continue;
            }

            $since = $watcher->created_at instanceof CarbonImmutable
                ? $watcher->created_at
                : ($watcher->created_at ? CarbonImmutable::parse((string) $watcher->created_at) : $pack->now);
            $taskId = $this->canonical->watcherTaskId($watcher);
            $task = $taskId !== null ? ($tasks[$taskId] ?? null) : null;
            $subject = $task instanceof Task ? (string) $task->title : null;

            $items[] = new SynthesisItem(
                kind: 'waiting',
                title: $subject !== null
                    ? HumanSynthesisText::waitingTitle($subject)
                    : HumanSynthesisText::plain($watcher->name),
                why: HumanWatcherDescription::sentence($watcher, ['task' => $subject], $pack->timezone),
                since: $since->toIso8601String(),
                score: $this->ageScore($since, $pack->now),
                sources: [new SourceRef(watcherId: (int) $watcher->id, projectId: $watcher->project_id, entityId: $watcher->knowledge_entity_id, taskId: $taskId, domain: 'watcher')],
                extra: [
                    'person' => null,
                    'suggested_follow_up' => $this->followUp($since, $pack->now),
                ],
                fingerprint: 'waiting:watcher:'.$watcher->id,
            );
        }

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task || ! TaskLifecycle::isOpen($task)) {
                continue;
            }

            $metadata = $task->metadata ?? [];

            if (($metadata['external_dependency'] ?? false) !== true && ($metadata['waiting_for'] ?? null) === null) {
                continue;
            }

            $label = is_string($metadata['waiting_for'] ?? null)
                ? HumanSynthesisText::waitingOn((string) $metadata['waiting_for'])
                : HumanSynthesisText::waitingTitle((string) $task->title);
            $since = $task->created_at ? CarbonImmutable::parse((string) $task->created_at) : $pack->now;
            $items[] = new SynthesisItem(
                kind: 'waiting',
                title: $label,
                why: 'Работа не двинется, пока не ответят снаружи.',
                since: $since->toIso8601String(),
                dueAt: optional($task->due_at)?->toIso8601String(),
                score: $this->ageScore($since, $pack->now) + 20,
                sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                extra: [
                    'external' => true,
                    'suggested_follow_up' => $this->followUp($since, $pack->now),
                ],
                fingerprint: 'waiting:task:'.$task->id,
            );
        }

        $closedEntities = $this->canonical->closedTaskEntityIds($pack);

        foreach ($pack->relationships as $relation) {
            if (! $relation instanceof KnowledgeRelationship || $relation->type !== KnowledgeRelationType::WaitingOn) {
                continue;
            }

            if (isset($closedEntities[(int) $relation->target_entity_id])) {
                continue;
            }

            $target = $this->canonical->entityLabel($pack, $relation->targetEntity, $relation->target_entity_id);
            $since = $relation->first_seen_at ?? $pack->now;
            $items[] = new SynthesisItem(
                kind: 'waiting',
                title: HumanSynthesisText::waitingOn($target ?? 'ответа'),
                why: 'Об этом договаривались, ответа пока нет.',
                since: $since->toIso8601String(),
                score: $this->ageScore($since, $pack->now),
                sources: [new SourceRef(entityId: (int) $relation->target_entity_id, domain: 'knowledge')],
                extra: [
                    'external' => true,
                    'person' => $relation->targetEntity?->name,
                    'suggested_follow_up' => $this->followUp($since, $pack->now),
                ],
                fingerprint: 'waiting:relation:'.$relation->id,
            );
        }

        return $items;
    }

    /**
     * Watchers bound to a task that is no longer open can never fire again, so they must not be
     * reported as something the user is still waiting for.
     *
     * @param  iterable<mixed>  $watchers
     * @return array<int, true>
     */
    public function staleWatcherIds(iterable $watchers): array
    {
        $tasks = $this->watcherTasks($watchers);

        if ($tasks === []) {
            return [];
        }

        $stale = [];

        foreach ($watchers as $watcher) {
            if (! $watcher instanceof Watcher) {
                continue;
            }

            $taskId = $this->canonical->watcherTaskId($watcher);
            $task = $taskId !== null ? ($tasks[$taskId] ?? null) : null;

            if ($task instanceof Task && ! TaskLifecycle::isOpen($task)) {
                $stale[(int) $watcher->id] = true;
            }
        }

        return $stale;
    }

    /**
     * @param  iterable<mixed>  $watchers
     * @return array<int, Task>
     */
    private function watcherTasks(iterable $watchers): array
    {
        $taskIds = [];
        $userIds = [];

        foreach ($watchers as $watcher) {
            if (! $watcher instanceof Watcher) {
                continue;
            }

            $taskId = $this->canonical->watcherTaskId($watcher);

            if ($taskId !== null) {
                $taskIds[$taskId] = true;
                $userIds[(int) $watcher->user_id] = true;
            }
        }

        if ($taskIds === []) {
            return [];
        }

        return Task::query()
            ->whereIn('user_id', array_keys($userIds))
            ->whereIn('id', array_keys($taskIds))
            ->limit(60)
            ->get()
            ->keyBy(static fn (Task $task): int => (int) $task->id)
            ->all();
    }

    private function ageScore(CarbonImmutable $since, CarbonImmutable $now): int
    {
        return min(40, (int) $since->diffInDays($now) * 2);
    }

    private function followUp(CarbonImmutable $since, CarbonImmutable $now): string
    {
        $days = max(1, (int) config('synthesis.waiting_follow_up_days', 3));
        $age = (int) $since->diffInDays($now);

        if ($age >= $days) {
            return 'Ждём уже '.$age.' '.HumanMoment::days($age).' — стоит напомнить о себе.';
        }

        return 'Если ответа не будет ещё '.$days.' '.HumanMoment::days($days).', напомните о себе.';
    }
}
