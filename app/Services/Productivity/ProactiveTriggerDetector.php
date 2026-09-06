<?php

namespace App\Services\Productivity;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;

final class ProactiveTriggerDetector
{
    public function __construct(
        private readonly ProactivePolicy $policy = new ProactivePolicy,
    ) {}

    /**
     * @return list<array{trigger: string, dedupe_key: string, title: string, body: string, source_id: int}>
     */
    public function forTask(Task $task, CarbonImmutable $now): array
    {
        if (! TaskLifecycle::isOpen($task) || $task->due_at === null) {
            return [];
        }

        $due = $task->due_at->utc();
        $events = [];

        if ($due->lessThan($now->utc())) {
            $events[] = [
                'trigger' => 'task_overdue',
                'dedupe_key' => 'proactive:task_overdue:'.$task->id,
                'title' => 'Просроченная задача',
                'body' => 'Задача всё ещё открыта и просрочена: '.$task->title,
                'source_id' => (int) $task->id,
            ];
        } elseif (
            in_array($task->priority, [TaskPriority::High, TaskPriority::Urgent], true)
            && $due->lessThanOrEqualTo($now->utc()->addHours($this->policy->approachHours()))
        ) {
            $events[] = [
                'trigger' => 'task_approaching',
                'dedupe_key' => 'proactive:task_approaching:'.$task->id.':'.$now->utc()->toDateString(),
                'title' => 'Скоро дедлайн',
                'body' => 'Скоро срок у важной задачи: '.$task->title,
                'source_id' => (int) $task->id,
            ];
        }

        return $events;
    }
}
