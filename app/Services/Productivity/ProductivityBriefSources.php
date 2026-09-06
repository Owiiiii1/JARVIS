<?php

namespace App\Services\Productivity;

/**
 * @phpstan-type BriefItem array{id?: int, title: string, due_at?: ?string, priority?: string, status?: string, run_at?: ?string}
 */
final class ProductivityBriefSources
{
    /**
     * @param  list<BriefItem>  $calendar
     * @param  list<BriefItem>  $tasksToday
     * @param  list<BriefItem>  $tasksOverdue
     * @param  list<BriefItem>  $tasksUpcoming
     * @param  list<BriefItem>  $tasksCompleted
     * @param  list<BriefItem>  $reminders
     * @param  list<BriefItem>  $projects
     * @param  list<BriefItem>  $notifications
     * @param  list<string>  $attention
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $timezone,
        public readonly string $localDate,
        public readonly array $calendar = [],
        public readonly array $tasksToday = [],
        public readonly array $tasksOverdue = [],
        public readonly array $tasksUpcoming = [],
        public readonly array $tasksCompleted = [],
        public readonly array $reminders = [],
        public readonly array $projects = [],
        public readonly array $notifications = [],
        public readonly array $attention = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'timezone' => $this->timezone,
            'local_date' => $this->localDate,
            'calendar' => $this->calendar,
            'tasks_today' => $this->tasksToday,
            'tasks_overdue' => $this->tasksOverdue,
            'tasks_upcoming' => $this->tasksUpcoming,
            'tasks_completed' => $this->tasksCompleted,
            'reminders' => $this->reminders,
            'projects' => $this->projects,
            'notifications' => $this->notifications,
            'attention' => $this->attention,
        ];
    }
}
