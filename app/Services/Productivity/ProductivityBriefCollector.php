<?php

namespace App\Services\Productivity;

use App\Enums\ProductivityBriefMode;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\JarvisNotification;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class ProductivityBriefCollector
{
    /**
     * @param  list<Task>  $tasks
     * @param  list<Reminder>  $reminders
     * @param  list<array{title: string, start?: ?string}>  $calendar
     * @param  list<Project>  $projects
     * @param  list<JarvisNotification>  $notifications
     */
    public function collect(
        User $user,
        ProductivityBriefMode $mode,
        CarbonImmutable $now,
        array $tasks,
        array $reminders,
        array $calendar = [],
        array $projects = [],
        array $notifications = [],
    ): ProductivityBriefSources {
        $timezone = $this->timezone($user);
        $localNow = $this->local($now, $timezone);
        $today = $localNow->toDateString();
        $tasksToday = [];
        $overdue = [];
        $upcoming = [];
        $completed = [];
        $attention = [];

        foreach ($tasks as $task) {
            if ((int) $task->user_id !== (int) $user->id) {
                continue;
            }

            $item = [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'due_at' => optional($task->due_at)?->toIso8601String(),
            ];

            if (TaskLifecycle::isOpen($task) && $task->due_at !== null && $task->due_at->utc()->lessThan($now->utc())) {
                $overdue[] = $item;
            } elseif (TaskLifecycle::isOpen($task) && $task->due_at !== null && $this->sameLocalDate($task->due_at, $timezone, $today)) {
                $tasksToday[] = $item;
            } elseif (TaskLifecycle::isOpen($task) && $task->due_at !== null && $this->inUpcomingWindow($task->due_at, $mode, $localNow, $timezone)) {
                $upcoming[] = $item;
            } elseif ($mode !== ProductivityBriefMode::Daily && $task->status === TaskStatus::Completed && $this->completedInWindow($task, $mode, $localNow, $timezone)) {
                $completed[] = $item;
            }

            if (TaskLifecycle::isOpen($task) && in_array($task->priority, [TaskPriority::Urgent, TaskPriority::High], true)) {
                $attention[] = $task->title;
            }
        }

        foreach ($overdue as $item) {
            $attention[] = 'Просрочено: '.$item['title'];
        }

        $reminderItems = [];

        foreach ($reminders as $reminder) {
            if ((int) $reminder->user_id !== (int) $user->id || ! ReminderLifecycle::isOpen($reminder)) {
                continue;
            }

            $reminderItems[] = [
                'id' => (int) $reminder->id,
                'title' => (string) $reminder->text,
                'run_at' => optional($reminder->run_at)?->toIso8601String(),
            ];
        }

        $projectItems = [];

        if ($user->canUseCapability(UserCapability::PROJECTS)) {
            foreach ($projects as $project) {
                if ((int) $project->user_id !== (int) $user->id) {
                    continue;
                }

                $projectItems[] = [
                    'id' => (int) $project->id,
                    'title' => (string) $project->name,
                    'status' => $project->status->value,
                ];
            }
        }

        $notificationItems = [];

        foreach ($notifications as $notification) {
            if ((int) $notification->user_id !== (int) $user->id) {
                continue;
            }

            $notificationItems[] = [
                'id' => (int) $notification->id,
                'title' => (string) $notification->title,
            ];
        }

        $ownedCalendar = $user->canUseCapability(UserCapability::GOOGLE_CALENDAR) ? $calendar : [];

        return new ProductivityBriefSources(
            mode: $mode->value,
            timezone: $timezone,
            localDate: $today,
            calendar: $ownedCalendar,
            tasksToday: $tasksToday,
            tasksOverdue: $overdue,
            tasksUpcoming: array_slice($upcoming, 0, 8),
            tasksCompleted: $completed,
            reminders: array_slice($reminderItems, 0, 8),
            projects: array_slice($projectItems, 0, 8),
            notifications: array_slice($notificationItems, 0, 5),
            attention: array_values(array_unique(array_slice($attention, 0, 8))),
        );
    }

    private function timezone(User $user): string
    {
        $timezone = (string) ($user->timezone ?: 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return 'UTC';
        }

        return $timezone;
    }

    private function local(CarbonImmutable $now, string $timezone): CarbonImmutable
    {
        try {
            return $now->utc()->setTimezone($timezone);
        } catch (Exception) {
            return $now->utc();
        }
    }

    private function sameLocalDate(CarbonImmutable $utc, string $timezone, string $today): bool
    {
        try {
            return $utc->utc()->setTimezone($timezone)->toDateString() === $today;
        } catch (Exception) {
            return $utc->utc()->toDateString() === $today;
        }
    }

    private function inUpcomingWindow(
        CarbonImmutable $dueAt,
        ProductivityBriefMode $mode,
        CarbonImmutable $localNow,
        string $timezone,
    ): bool {
        try {
            $localDue = $dueAt->utc()->setTimezone($timezone);
        } catch (Exception) {
            $localDue = $dueAt->utc();
        }

        $end = match ($mode) {
            ProductivityBriefMode::Daily => $localNow->addDays(3)->endOfDay(),
            ProductivityBriefMode::Evening => $localNow->addDay()->endOfDay(),
            ProductivityBriefMode::Weekly => $localNow->addWeek()->endOfDay(),
        };

        return $localDue->greaterThan($localNow) && $localDue->lessThanOrEqualTo($end);
    }

    private function completedInWindow(
        Task $task,
        ProductivityBriefMode $mode,
        CarbonImmutable $localNow,
        string $timezone,
    ): bool {
        if ($task->completed_at === null) {
            return false;
        }

        try {
            $completed = $task->completed_at->utc()->setTimezone($timezone);
        } catch (Exception) {
            $completed = $task->completed_at->utc();
        }

        return match ($mode) {
            ProductivityBriefMode::Evening => $completed->toDateString() === $localNow->toDateString(),
            ProductivityBriefMode::Weekly => $completed->greaterThanOrEqualTo($localNow->startOfWeek()),
            default => false,
        };
    }
}
