<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Reminder;
use App\Models\Task;
use App\Services\Reminders\ReminderLifecycle;
use Carbon\CarbonImmutable;

final class TaskLifecycle
{
    /**
     * @return list<TaskStatus>
     */
    public static function openStatuses(): array
    {
        return [TaskStatus::Open, TaskStatus::InProgress];
    }

    public static function isOpen(Task $task): bool
    {
        return in_array($task->status, self::openStatuses(), true);
    }

    public static function isCompletable(Task $task): bool
    {
        return self::isOpen($task);
    }

    public static function isCancellable(Task $task): bool
    {
        return self::isOpen($task);
    }

    public static function isStartable(Task $task): bool
    {
        return $task->status === TaskStatus::Open;
    }

    public static function isReopenable(Task $task): bool
    {
        return $task->status === TaskStatus::Completed;
    }

    public static function markStarted(Task $task): void
    {
        if (! self::isStartable($task)) {
            throw new TaskException('not_startable', 'This task cannot be started.');
        }

        $task->forceFill([
            'status' => TaskStatus::InProgress,
        ]);
    }

    /**
     * @param  list<Task>  $children
     * @param  list<Reminder>  $linkedReminders
     * @return list<Reminder>
     */
    public static function markCompleted(
        Task $task,
        CarbonImmutable $now,
        array $children = [],
        array $linkedReminders = [],
        bool $force = false,
    ): array {
        if (! self::isCompletable($task)) {
            throw new TaskException('not_completable', 'This task cannot be completed.');
        }

        $openChildren = array_values(array_filter(
            $children,
            static fn (Task $child): bool => self::isOpen($child),
        ));

        if ($openChildren !== [] && ! $force) {
            throw new TaskException(
                'open_subtasks',
                'This task has unfinished subtasks. Confirm to complete it anyway.',
                $openChildren,
            );
        }

        $task->forceFill([
            'status' => TaskStatus::Completed,
            'completed_at' => $now->utc(),
            'cancelled_at' => null,
        ]);

        return self::cancelFutureLinkedReminders($linkedReminders, $now);
    }

    /**
     * @param  list<Reminder>  $linkedReminders
     * @return list<Reminder>
     */
    public static function markCancelled(Task $task, CarbonImmutable $now, array $linkedReminders = []): array
    {
        if (! self::isCancellable($task)) {
            throw new TaskException('not_cancellable', 'This task cannot be cancelled.');
        }

        $task->forceFill([
            'status' => TaskStatus::Cancelled,
            'cancelled_at' => $now->utc(),
        ]);

        return self::cancelFutureLinkedReminders($linkedReminders, $now);
    }

    public static function markReopened(Task $task): void
    {
        if (! self::isReopenable($task)) {
            throw new TaskException('not_reopenable', 'Only completed tasks can be reopened.');
        }

        $task->forceFill([
            'status' => TaskStatus::Open,
            'completed_at' => null,
        ]);
    }

    public static function setPriority(Task $task, TaskPriority $priority): void
    {
        if (! self::isOpen($task)) {
            throw new TaskException('not_editable', 'This task cannot be updated.');
        }

        $task->forceFill(['priority' => $priority]);
    }

    public static function setDueAt(Task $task, ?CarbonImmutable $dueAt, ?string $timezone): void
    {
        if (! self::isOpen($task)) {
            throw new TaskException('not_editable', 'This task cannot be updated.');
        }

        $task->forceFill([
            'due_at' => $dueAt?->utc(),
            'timezone' => $timezone,
        ]);
    }

    /**
     * @param  list<Reminder>  $linkedReminders
     * @return list<Reminder>
     */
    public static function cancelFutureLinkedReminders(array $linkedReminders, CarbonImmutable $now): array
    {
        $cancelled = [];

        foreach ($linkedReminders as $reminder) {
            if (! ReminderLifecycle::isOpen($reminder)) {
                continue;
            }

            ReminderLifecycle::markCancelled($reminder, $now);
            $cancelled[] = $reminder;
        }

        return $cancelled;
    }

    public static function wouldCreateCycle(Task $child, Task $parent): bool
    {
        if ((int) $child->id === (int) $parent->id) {
            return true;
        }

        $walk = $parent;

        while ($walk->parent_task_id) {
            if ((int) $walk->parent_task_id === (int) $child->id) {
                return true;
            }

            if ($walk->relationLoaded('parent') && $walk->parent instanceof Task) {
                $walk = $walk->parent;

                continue;
            }

            break;
        }

        return false;
    }
}
