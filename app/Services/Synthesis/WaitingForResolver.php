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
use Carbon\CarbonImmutable;

final class WaitingForResolver
{
    /**
     * @return list<SynthesisItem>
     */
    public function resolve(FactPack $pack): array
    {
        $items = [];

        foreach ($pack->watchers as $watcher) {
            if (! $watcher instanceof Watcher || $watcher->status !== WatcherStatus::Active) {
                continue;
            }

            if ($watcher->mode !== WatcherMode::OneShot && $watcher->health !== WatcherHealth::Waiting) {
                continue;
            }

            if ($watcher->mode === WatcherMode::OneShot || $watcher->health === WatcherHealth::Waiting) {
                $since = $watcher->created_at instanceof CarbonImmutable
                    ? $watcher->created_at
                    : ($watcher->created_at ? CarbonImmutable::parse((string) $watcher->created_at) : $pack->now);
                $items[] = new SynthesisItem(
                    kind: 'waiting',
                    title: $watcher->name,
                    why: 'One-shot watcher still waiting for its event.',
                    since: $since->toIso8601String(),
                    score: $this->ageScore($since, $pack->now),
                    sources: [new SourceRef(watcherId: (int) $watcher->id, projectId: $watcher->project_id, entityId: $watcher->knowledge_entity_id, taskId: $watcher->task_id, domain: 'watcher')],
                    extra: [
                        'person' => null,
                        'suggested_follow_up' => $this->followUp($since, $pack->now),
                    ],
                    fingerprint: 'waiting:watcher:'.$watcher->id,
                );
            }
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
                ? (string) $metadata['waiting_for']
                : 'External dependency: '.$task->title;
            $since = $task->created_at ? CarbonImmutable::parse((string) $task->created_at) : $pack->now;
            $items[] = new SynthesisItem(
                kind: 'waiting',
                title: $label,
                why: 'Open task is marked as waiting on an external dependency.',
                since: $since->toIso8601String(),
                dueAt: optional($task->due_at)?->toIso8601String(),
                score: $this->ageScore($since, $pack->now) + 20,
                sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                extra: [
                    'suggested_follow_up' => $this->followUp($since, $pack->now),
                ],
                fingerprint: 'waiting:task:'.$task->id,
            );
        }

        foreach ($pack->relationships as $relation) {
            if (! $relation instanceof KnowledgeRelationship || $relation->type !== KnowledgeRelationType::WaitingOn) {
                continue;
            }

            $target = $relation->targetEntity;
            $since = $relation->first_seen_at ?? $pack->now;
            $items[] = new SynthesisItem(
                kind: 'waiting',
                title: 'Waiting on '.($target?->name ?? 'entity #'.$relation->target_entity_id),
                why: 'Explicit waiting_on relationship.',
                since: $since->toIso8601String(),
                score: $this->ageScore($since, $pack->now),
                sources: [new SourceRef(entityId: (int) $relation->target_entity_id, domain: 'knowledge')],
                extra: [
                    'person' => $target?->name,
                    'suggested_follow_up' => $this->followUp($since, $pack->now),
                ],
                fingerprint: 'waiting:relation:'.$relation->id,
            );
        }

        return $items;
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
            return 'Follow up now; waiting '.$age.'d.';
        }

        return 'Follow up after '.$days.'d without a reply.';
    }
}
