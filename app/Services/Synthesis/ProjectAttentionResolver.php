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
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanRelationLabel;
use App\Services\Workspace\Presentation\HumanSynthesisText;
use App\Services\Workspace\Presentation\HumanWatcherDescription;
use Carbon\CarbonImmutable;

final class ProjectAttentionResolver
{
    public function __construct(
        private readonly CanonicalStateResolver $canonical = new CanonicalStateResolver,
    ) {}

    /**
     * @param  list<SynthesisItem>  $waiting
     * @return list<SynthesisItem>
     */
    public function blockers(FactPack $pack, array $waiting): array
    {
        $items = [];

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
                    ? 'Срок прошёл, и предыдущая задача ещё не закрыта.'
                    : 'Срок уже прошёл.';
                $items[] = new SynthesisItem(
                    kind: 'blocker',
                    title: $task->title,
                    why: $why,
                    since: $task->due_at->toIso8601String(),
                    dueAt: $task->due_at->toIso8601String(),
                    score: 100,
                    sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                    recommendedNextStep: 'Закройте работу или перенесите срок.',
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
                    title: HumanWatcherDescription::sentence($watcher, [], $pack->timezone),
                    why: 'Автоматизация не работает — похоже, подключение разорвано.',
                    score: 70,
                    sources: [new SourceRef(watcherId: (int) $watcher->id, projectId: $watcher->project_id, domain: 'watcher')],
                    recommendedNextStep: 'Переподключите сервис или приостановите автоматизацию.',
                    fingerprint: 'blocker:watcher:'.$watcher->id,
                );
            }
        }

        $closedEntities = $this->canonical->closedTaskEntityIds($pack);

        foreach ($pack->relationships as $relation) {
            if (! $relation instanceof KnowledgeRelationship || $relation->type !== KnowledgeRelationType::DependsOn) {
                continue;
            }

            // Knowledge keeps the history of the dependency; the task table decides whether it
            // still blocks anything. A closed task on either side means nothing is blocked.
            if (isset($closedEntities[(int) $relation->target_entity_id])
                || isset($closedEntities[(int) $relation->source_entity_id])) {
                continue;
            }

            $target = $this->canonical->entityLabel($pack, $relation->targetEntity, $relation->target_entity_id);
            $items[] = new SynthesisItem(
                kind: 'blocker',
                title: HumanRelationLabel::dependency($target ?? 'связанной работы'),
                why: 'Пока это не закрыто, работа стоит.',
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

            $external = ($item->extra['external'] ?? false) === true;

            if ($ageDays < max(1, (int) config('synthesis.waiting_follow_up_days', 3)) && ! $external) {
                continue;
            }

            if ($external || ($item->sources[0]->taskId ?? null) !== null) {
                $clone = new SynthesisItem(
                    kind: 'blocker',
                    title: $item->title,
                    why: 'Работа ждёт ответа со стороны.',
                    since: $item->since,
                    score: 75,
                    sources: $item->sources,
                    extra: ['external' => $external],
                    recommendedNextStep: $item->extra['suggested_follow_up'] ?? 'Напомните о себе.',
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
                    title: HumanSynthesisText::plain($event->title),
                    why: 'Об этой помехе договорились в переписке.',
                    since: optional($event->occurred_at)?->toIso8601String(),
                    score: 80,
                    sources: [new SourceRef(knowledgeEventId: (int) $event->id, domain: 'knowledge')],
                    fingerprint: 'blocker:event:'.$event->id,
                );
            }
        }

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
                    why: HumanSynthesisText::deadline(24),
                    dueAt: $task->due_at->toIso8601String(),
                    score: 60,
                    sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                    recommendedNextStep: 'Закончите сегодня или перенесите срок.',
                    extra: ['reason' => 'deadline_24h'],
                    fingerprint: 'attention:due24:'.$task->id,
                );
            } elseif ($hours > 24 && $hours <= $riskHours) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $task->title,
                    why: HumanSynthesisText::deadline($riskHours),
                    dueAt: $task->due_at->toIso8601String(),
                    score: 50,
                    sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                    recommendedNextStep: 'Проверьте, что осталось сделать до срока.',
                    extra: ['reason' => 'deadline_risk'],
                    fingerprint: 'attention:due48:'.$task->id,
                );
            }

            $progressAt = $task->updated_at ? CarbonImmutable::parse((string) $task->updated_at) : null;

            if ($task->due_at->utc()->greaterThan($pack->now) && $progressAt !== null && $progressAt->diffInDays($pack->now) >= $inactivityDays) {
                $items[] = new SynthesisItem(
                    kind: 'attention',
                    title: $task->title,
                    why: 'Срок назначен, но давно ничего не происходило.',
                    dueAt: $task->due_at->toIso8601String(),
                    score: 35,
                    sources: [new SourceRef(taskId: (int) $task->id, domain: 'task')],
                    recommendedNextStep: 'Продолжите работу или обновите срок.',
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
                    why: 'Ждём уже '.$ageDays.' '.HumanMoment::days($ageDays).'.',
                    since: $item->since,
                    score: 40 + min(20, $ageDays),
                    sources: $item->sources,
                    recommendedNextStep: $item->extra['suggested_follow_up'] ?? 'Напомните о себе.',
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
                    why: 'Срок договорённости уже близко.',
                    dueAt: $item->dueAt,
                    score: 45,
                    sources: $item->sources,
                    recommendedNextStep: 'Выполните обещанное или договоритесь о новом сроке.',
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
                    why: 'В проекте есть открытая работа, но уже '.$inactivityDays.'+ '.HumanMoment::days($inactivityDays).' ничего не происходило.',
                    score: 38,
                    sources: [new SourceRef(projectId: (int) $project->id, domain: 'project')],
                    recommendedNextStep: 'Посмотрите, что осталось открытым.',
                    extra: ['reason' => 'stale_project', 'status_label' => 'Нет недавней активности', 'suggestion_type' => 'stale_project'],
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
            if (($item->extra['external'] ?? false) === true) {
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
