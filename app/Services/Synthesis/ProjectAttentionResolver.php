<?php

namespace App\Services\Synthesis;

use App\Enums\KnowledgeRelationType;
use App\Enums\ProjectStatus;
use App\Enums\WatcherHealth;
use App\Enums\WatcherStatus;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Project;
use App\Models\Task;
use App\Models\Watcher;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;

final class ProjectAttentionResolver
{
    /**
     * @param  list<SynthesisItem>  $waiting
     * @return list<SynthesisItem>
     */
    public function blockers(FactPack $pack, array $waiting): array
    {
        $items = [];
        $tasksById = [];

        foreach ($pack->tasks as $task) {
            if ($task instanceof Task) {
                $tasksById[(int) $task->id] = $task;
            }
        }

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task || ! TaskLifecycle::isOpen($task) || $task->due_at === null) {
                continue;
            }

            if (! $task->due_at->utc()->lessThan($pack->now)) {
                continue;
            }

            $parent = $task->parent;
            $prerequisiteOpen = $parent instanceof Task && TaskLifecycle::isOpen($parent);

            if ($prerequisiteOpen || $parent === null) {
                $why = $prerequisiteOpen
                    ? 'Overdue and prerequisite task is still open.'
                    : 'Task is overdue.';
                $items[] = new SynthesisItem(
                    kind: 'blocker',
                    title: $task->title,
                    why: $why,
                    since: $task->due_at->toIso8601String(),
                    dueAt: $task->due_at->toIso8601String(),
                    score: 100,
                    sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                    recommendedNextStep: 'Close the overdue work or move the deadline.',
                    fingerprint: 'blocker:overdue:'.$task->id,
                );
            }
        }

        foreach ($pack->watchers as $watcher) {
            if (! $watcher instanceof Watcher) {
                continue;
            }

            if ($watcher->health === WatcherHealth::Blocked || $watcher->status === WatcherStatus::Failed) {
                $items[] = new SynthesisItem(
                    kind: 'blocker',
                    title: $watcher->name,
                    why: 'Watcher is blocked (disconnected integration or failed checks).',
                    score: 70,
                    sources: [new SourceRef(watcherId: (int) $watcher->id, projectId: $watcher->project_id, domain: 'watcher')],
                    recommendedNextStep: 'Reconnect the integration or pause the watcher.',
                    fingerprint: 'blocker:watcher:'.$watcher->id,
                );
            }
        }

        foreach ($pack->relationships as $relation) {
            if (! $relation instanceof KnowledgeRelationship || $relation->type !== KnowledgeRelationType::DependsOn) {
                continue;
            }

            $target = $relation->targetEntity;
            $items[] = new SynthesisItem(
                kind: 'blocker',
                title: 'Depends on '.($target?->name ?? 'entity #'.$relation->target_entity_id),
                why: 'Explicit depends_on relationship is still active.',
                score: 80,
                sources: [new SourceRef(entityId: (int) $relation->target_entity_id, domain: 'knowledge')],
                fingerprint: 'blocker:depends:'.$relation->id,
            );
        }

        foreach ($waiting as $item) {
            $ageDays = 0;
            if (is_string($item->since)) {
                $ageDays = (int) CarbonImmutable::parse($item->since)->diffInDays($pack->now);
            }

            if ($ageDays < max(1, (int) config('synthesis.waiting_follow_up_days', 3)) && ($item->extra['external'] ?? false) !== true) {
                if (! str_contains(mb_strtolower($item->why ?? ''), 'external')) {
                    continue;
                }
            }

            if (str_contains(mb_strtolower($item->why ?? ''), 'external') || ($item->sources[0]->taskId ?? null) !== null) {
                $clone = new SynthesisItem(
                    kind: 'blocker',
                    title: $item->title,
                    why: 'Waiting-for external dependency.',
                    since: $item->since,
                    score: 75,
                    sources: $item->sources,
                    recommendedNextStep: $item->extra['suggested_follow_up'] ?? 'Follow up.',
                    fingerprint: 'blocker:waiting:'.$item->fingerprint,
                );
                $items[] = $clone;
            }
        }

        foreach ($pack->events as $event) {
            if (! $event instanceof KnowledgeEvent) {
                continue;
            }

            $metadata = $event->metadata ?? [];

            if (($metadata['blocked'] ?? false) === true || str_contains(mb_strtolower($event->title), 'blocked by')) {
                $items[] = new SynthesisItem(
                    kind: 'blocker',
                    title: $event->title,
                    why: 'Explicit blocker recorded as a knowledge event.',
                    since: optional($event->occurred_at)?->toIso8601String(),
                    score: 80,
                    sources: [new SourceRef(knowledgeEventId: (int) $event->id, domain: 'knowledge')],
                    fingerprint: 'blocker:event:'.$event->id,
                );
            }
        }

        unset($tasksById);

        return $items;
    }

    /**
     * @param  list<SynthesisItem>  $blockers
     * @param  list<SynthesisItem>  $waiting
     * @param  list<SynthesisItem>  $commitments
     * @return list<SynthesisItem>
     */
    public function attention(FactPack $pack, array $blockers, array $waiting, array $commitments): array
    {
        $items = [];
        $riskHours = max(1, (int) config('synthesis.deadline_risk_hours', 48));
        $inactivityDays = max(1, (int) config('synthesis.inactivity_days', 7));

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task || ! TaskLifecycle::isOpen($task) || $task->due_at === null) {
                continue;
            }

            $due = $task->due_at->utc();

            if ($due->lessThanOrEqualTo($pack->now)) {
                continue;
            }

            $hours = (int) $pack->now->diffInHours($due);

            if ($hours <= 24) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $task->title,
                    why: 'Deadline within 24h.',
                    dueAt: $task->due_at->toIso8601String(),
                    score: 60,
                    sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                    recommendedNextStep: 'Finish or reschedule today.',
                    extra: ['reason' => 'deadline_24h'],
                    fingerprint: 'attention:due24:'.$task->id,
                );
            } elseif ($hours > 24 && $hours <= $riskHours) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $task->title,
                    why: 'Deadline within 48h.',
                    dueAt: $task->due_at->toIso8601String(),
                    score: 50,
                    sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                    recommendedNextStep: 'Check remaining work before the deadline.',
                    extra: ['reason' => 'deadline_risk'],
                    fingerprint: 'attention:due48:'.$task->id,
                );
            }

            $progressAt = $task->updated_at ? CarbonImmutable::parse((string) $task->updated_at) : null;

            if ($task->due_at->utc()->greaterThan($pack->now) && $progressAt !== null && $progressAt->diffInDays($pack->now) >= $inactivityDays) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $task->title,
                    why: 'Due task with no recent progress.',
                    dueAt: $task->due_at->toIso8601String(),
                    score: 35,
                    sources: [new SourceRef(taskId: (int) $task->id, domain: 'task')],
                    recommendedNextStep: 'Update status or continue the work.',
                    extra: ['reason' => 'no_progress'],
                    fingerprint: 'attention:stale-task:'.$task->id,
                );
            }
        }

        foreach ($waiting as $item) {
            $ageDays = is_string($item->since) ? (int) CarbonImmutable::parse($item->since)->diffInDays($pack->now) : 0;
            $threshold = max(1, (int) config('synthesis.waiting_follow_up_days', 3));

            if ($ageDays >= $threshold) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $item->title,
                    why: 'Waiting-for older than '.$threshold.'d.',
                    since: $item->since,
                    score: 40 + min(20, $ageDays),
                    sources: $item->sources,
                    recommendedNextStep: $item->extra['suggested_follow_up'] ?? 'Follow up.',
                    extra: ['reason' => 'waiting_too_long', 'suggestion_type' => 'follow_up'],
                    fingerprint: 'attention:waiting:'.$item->fingerprint,
                );
            }
        }

        foreach ($commitments as $item) {
            if ($item->dueAt === null) {
                continue;
            }

            $due = CarbonImmutable::parse($item->dueAt);

            if ($due->lessThanOrEqualTo($pack->now->addDay())) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $item->title,
                    why: 'Commitment due soon.',
                    dueAt: $item->dueAt,
                    score: 45,
                    sources: $item->sources,
                    recommendedNextStep: 'Deliver or renegotiate.',
                    extra: ['reason' => 'commitment_due', 'suggestion_type' => 'commitment_due'],
                    fingerprint: 'attention:commitment:'.$item->fingerprint,
                );
            }
        }

        foreach ($pack->projects as $project) {
            if (! $project instanceof Project || $project->status !== ProjectStatus::Active) {
                continue;
            }

            $open = array_values(array_filter(
                $pack->tasks,
                static fn ($task): bool => $task instanceof Task && TaskLifecycle::isOpen($task) && (int) $task->project_id === (int) $project->id,
            ));
            $activeWatchers = array_values(array_filter(
                $pack->watchers,
                static fn ($watcher): bool => $watcher instanceof Watcher && $watcher->status === WatcherStatus::Active && (int) $watcher->project_id === (int) $project->id,
            ));
            $upcomingDeadline = false;

            foreach ($open as $task) {
                if ($task->due_at !== null && $task->due_at->utc()->lessThanOrEqualTo($pack->now->addDays(7))) {
                    $upcomingDeadline = true;
                }
            }

            $hasOpenWork = $open !== [] || $activeWatchers !== [] || $upcomingDeadline;

            if (! $hasOpenWork) {
                continue;
            }

            $lastActivity = $project->updated_at ? CarbonImmutable::parse((string) $project->updated_at) : null;

            foreach ($pack->events as $event) {
                $metadata = $event->metadata ?? [];
                $linked = isset($metadata['project_id']) && (int) $metadata['project_id'] === (int) $project->id;
                $entityLinked = $event->relationLoaded('entities')
                    && $event->entities->contains(static fn ($entity): bool => (int) $entity->project_id === (int) $project->id);

                if (! $linked && ! $entityLinked) {
                    continue;
                }

                if ($event->occurred_at instanceof CarbonImmutable && ($lastActivity === null || $event->occurred_at->greaterThan($lastActivity))) {
                    $lastActivity = $event->occurred_at;
                }
            }

            if ($lastActivity !== null && $lastActivity->diffInDays($pack->now) >= $inactivityDays) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $project->name,
                    why: 'Active project with open work and no activity for '.$inactivityDays.'+ days.',
                    score: 38,
                    sources: [new SourceRef(projectId: (int) $project->id, domain: 'project')],
                    recommendedNextStep: 'Check what is still open.',
                    extra: ['reason' => 'stale_project', 'status_label' => 'No recent activity', 'suggestion_type' => 'stale_project'],
                    fingerprint: 'attention:stale-project:'.$project->id,
                );
            }
        }

        foreach ($blockers as $blocker) {
            $items[] = new SynthesisItem(
                kind: 'attention',
                title: $blocker->title,
                why: $blocker->why,
                score: max(80, $blocker->score),
                sources: $blocker->sources,
                recommendedNextStep: $blocker->recommendedNextStep,
                extra: ['reason' => 'blocker', 'suggestion_type' => 'project_blocked'],
                fingerprint: 'attention:blocker:'.$blocker->fingerprint,
            );
        }

        return $items;
    }

    /**
     * @param  list<SynthesisItem>  $blockers
     * @param  list<SynthesisItem>  $waiting
     * @param  list<SynthesisItem>  $attention
     */
    public function projectLabels(FactPack $pack, array $blockers, array $waiting, array $attention): array
    {
        $labels = [];

        if ($blockers !== []) {
            $labels[] = 'Blocked';
        }

        foreach ($waiting as $item) {
            if (str_contains(mb_strtolower($item->why ?? ''), 'external') || str_contains(mb_strtolower($item->title), 'external')) {
                $labels[] = 'Waiting external';
                break;
            }
        }

        foreach ($attention as $item) {
            if (($item->extra['reason'] ?? '') === 'deadline_risk' || ($item->extra['reason'] ?? '') === 'deadline_24h') {
                $labels[] = 'Deadline risk';
            }

            if (($item->extra['reason'] ?? '') === 'stale_project') {
                $labels[] = 'No recent activity';
            }
        }

        if ($labels === [] && $pack->project?->status === ProjectStatus::Active) {
            $labels[] = 'Active';
        }

        return array_values(array_unique($labels));
    }
}
