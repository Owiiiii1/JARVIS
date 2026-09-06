<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\TaskStatus;
use App\Enums\WatcherConditionType;
use App\Enums\WatcherTriggerType;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class TimeWatcherSource implements WatcherSourceAdapter
{
    public function supports(Watcher $watcher): bool
    {
        return $watcher->trigger_type === WatcherTriggerType::TimeCondition && $watcher->task_id === null;
    }

    public function check(User $user, Watcher $watcher): array
    {
        $config = is_array($watcher->source_config) ? $watcher->source_config : [];
        $taskId = (int) ($config['task_id'] ?? 0);
        if ($taskId < 1) {
            return [];
        }

        $task = Task::query()->where('user_id', $user->id)->whereKey($taskId)->first();
        if ($task === null) {
            return [];
        }

        $now = CarbonImmutable::now('UTC');
        $due = $task->due_at instanceof CarbonImmutable ? $task->due_at->utc() : null;
        $open = in_array($task->status, [TaskStatus::Open, TaskStatus::InProgress], true);
        $hours = (int) ($watcher->condition_config['hours'] ?? 24);

        $match = match ($watcher->condition_type) {
            WatcherConditionType::OverdueBy => $open && $due !== null && $due->addHours(max(1, $hours))->lessThanOrEqualTo($now),
            WatcherConditionType::DeadlineWithin => $open && $due !== null && $due->greaterThan($now) && $due->lessThanOrEqualTo($now->addHours(max(1, $hours))),
            default => false,
        };

        if (! $match) {
            return [];
        }

        return [
            new WatcherObservation(
                sourceType: 'time',
                sourceId: (string) $task->id,
                eventType: $watcher->condition_type->value,
                fingerprint: WatcherSupport::fingerprint('time', (string) $watcher->id, (string) $task->id, $now->toDateString()),
                occurredAt: $now,
                title: (string) $task->title,
                metadata: ['status' => $task->status->value, 'due_at' => $due?->toIso8601String()],
                taskId: (int) $task->id,
                projectId: $task->project_id,
            ),
        ];
    }
}
