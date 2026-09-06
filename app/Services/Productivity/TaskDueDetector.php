<?php

namespace App\Services\Productivity;

use App\Enums\JarvisNotificationType;
use App\Models\Task;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class TaskDueDetector
{
    /**
     * @return list<array{type: JarvisNotificationType, dedupe_key: string, title: string, body: string}>
     */
    public function eventsFor(Task $task, CarbonImmutable $now, string $timezone): array
    {
        if (! TaskLifecycle::isOpen($task) || $task->due_at === null) {
            return [];
        }

        $due = $task->due_at->utc();

        if ($due->lessThan($now->utc())) {
            return [[
                'type' => JarvisNotificationType::TaskOverdue,
                'dedupe_key' => 'task_overdue:'.$task->id,
                'title' => 'Задача просрочена',
                'body' => 'Просрочено: '.$task->title,
            ]];
        }

        if ($this->isLocalToday($due, $timezone, $now)) {
            $date = $this->localDate($now, $timezone);

            return [[
                'type' => JarvisNotificationType::TaskDue,
                'dedupe_key' => 'task_due:'.$task->id.':'.$date,
                'title' => 'Задача на сегодня',
                'body' => 'Срок сегодня: '.$task->title,
            ]];
        }

        return [];
    }

    private function isLocalToday(CarbonImmutable $utc, string $timezone, CarbonImmutable $now): bool
    {
        return $this->localDate($utc, $timezone) === $this->localDate($now, $timezone);
    }

    private function localDate(CarbonImmutable $instant, string $timezone): string
    {
        try {
            new DateTimeZone($timezone);

            return $instant->utc()->setTimezone($timezone)->toDateString();
        } catch (Exception) {
            return $instant->utc()->toDateString();
        }
    }
}
