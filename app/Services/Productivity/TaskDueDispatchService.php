<?php

namespace App\Services\Productivity;

use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;

final class TaskDueDispatchService
{
    public function __construct(
        private readonly TaskDueDetector $detector = new TaskDueDetector,
        private readonly JarvisNotificationService $inbox = new JarvisNotificationService,
        private readonly NotificationUrlPolicy $urls = new NotificationUrlPolicy,
    ) {}

    public function dispatchDue(int $limit = 80): int
    {
        $now = CarbonImmutable::now('UTC');
        $created = 0;

        $tasks = Task::query()
            ->with('user')
            ->whereIn('status', TaskLifecycle::openStatuses())
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now->utc()->addDay())
            ->orderBy('due_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($tasks as $task) {
            $user = $task->user;

            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            $timezone = (string) ($task->timezone ?: $user->timezone ?: 'UTC');

            foreach ($this->detector->eventsFor($task, $now, $timezone) as $event) {
                $row = $this->inbox->record(
                    $user,
                    $event['type'],
                    $event['title'],
                    $event['body'],
                    $event['dedupe_key'],
                    'task',
                    (int) $task->id,
                    $this->urls->workspacePath($user, 'task='.$task->id, $task->source_conversation_id ? (int) $task->source_conversation_id : null),
                    [
                        'trigger' => $event['type']->value,
                        'source_id' => (int) $task->id,
                    ],
                );

                if ($row !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }
}
