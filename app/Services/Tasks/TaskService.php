<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Knowledge\KnowledgeDeterministicIngestor;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Synthesis\CommitmentLifecycle;
use App\Services\Users\UserCapability;
use App\Services\Watchers\WatcherEvaluationDispatcher;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanStatusLabel;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class TaskService
{
    public function __construct(
        private readonly KnowledgeDeterministicIngestor $knowledge = new KnowledgeDeterministicIngestor,
    ) {}

    public function create(
        User $user,
        string $title,
        ?string $description = null,
        ?TaskPriority $priority = null,
        ?CarbonImmutable $dueAt = null,
        ?string $timezone = null,
        ?Conversation $conversation = null,
        ?Message $sourceMessage = null,
        ?int $projectId = null,
        ?int $parentTaskId = null,
        ?string $calendarProvider = null,
        ?string $calendarId = null,
        ?string $calendarEventId = null,
    ): Task {
        $this->assertCanUse($user);
        $title = $this->normalizeTitle($title);
        $description = $this->normalizeDescription($description);
        $timezone = $this->normalizeTimezone($timezone ?? (string) ($user->timezone ?: 'UTC'));
        $conversation = $this->ownedConversation($user, $conversation);
        $sourceMessage = $this->ownedMessage($user, $conversation, $sourceMessage);
        $projectId = $this->ownedProjectId($user, $projectId);
        $parent = $parentTaskId !== null ? $this->findOwned($user, $parentTaskId) : null;

        if ($parentTaskId !== null && $parent === null) {
            throw new TaskException('not_found', 'Parent task was not found.');
        }

        if ($parent !== null && $parent->parent_task_id !== null) {
            throw new TaskException('nested_subtask', 'Subtasks cannot have their own subtasks.');
        }

        $this->assertCalendarReference($calendarProvider, $calendarId, $calendarEventId);

        $task = Task::query()->create([
            'user_id' => $user->id,
            'parent_task_id' => $parent?->id,
            'title' => $title,
            'description' => $description,
            'status' => TaskStatus::Open,
            'priority' => $priority ?? TaskPriority::Normal,
            'due_at' => $dueAt?->utc(),
            'timezone' => $dueAt !== null ? $timezone : ($timezone ?: (string) ($user->timezone ?: 'UTC')),
            'source_conversation_id' => $conversation?->id,
            'source_message_id' => $sourceMessage?->id,
            'project_id' => $projectId,
            'calendar_provider' => $calendarProvider,
            'calendar_id' => $calendarId,
            'calendar_event_id' => $calendarEventId,
            'metadata' => [],
        ]);

        $this->knowledge->taskCreated($task);
        $this->notifyWatchers($task);

        return $task;
    }

    public function validateCreate(User $user, string $title): void
    {
        $this->assertCanUse($user);
        $this->normalizeTitle($title);
    }

    public function updateOwned(User $user, int $taskId, array $attributes, bool $force = false): Task
    {
        $task = $this->requireOwned($user, $taskId);

        if (! TaskLifecycle::isOpen($task) && ! array_key_exists('status', $attributes)) {
            throw new TaskException('not_editable', 'This task cannot be updated.');
        }

        if (array_key_exists('title', $attributes) && $attributes['title'] !== null) {
            $task->title = $this->normalizeTitle((string) $attributes['title']);
        }

        if (array_key_exists('description', $attributes)) {
            $task->description = $this->normalizeDescription($attributes['description'] !== null ? (string) $attributes['description'] : null);
        }

        if (array_key_exists('priority', $attributes) && $attributes['priority'] !== null) {
            $task->priority = $this->normalizePriority($attributes['priority']);
        }

        if (array_key_exists('due_at', $attributes) || array_key_exists('timezone', $attributes)) {
            $timezone = $this->normalizeTimezone(
                isset($attributes['timezone']) && is_string($attributes['timezone']) && $attributes['timezone'] !== ''
                    ? $attributes['timezone']
                    : (string) ($task->timezone ?: $user->timezone ?: 'UTC'),
            );
            $dueAt = $attributes['due_at'] ?? false;

            if ($dueAt === null || $dueAt === '') {
                TaskLifecycle::setDueAt($task, null, $timezone);
            } elseif ($dueAt instanceof CarbonImmutable) {
                TaskLifecycle::setDueAt($task, $dueAt, $timezone);
            } elseif (is_string($dueAt)) {
                TaskLifecycle::setDueAt($task, $this->localWallTimeToUtc($dueAt, $timezone), $timezone);
            }
        }

        if (array_key_exists('project_id', $attributes)) {
            $task->project_id = $this->ownedProjectId($user, $attributes['project_id'] !== null ? (int) $attributes['project_id'] : null);
        }

        if (array_key_exists('calendar_provider', $attributes) || array_key_exists('calendar_id', $attributes) || array_key_exists('calendar_event_id', $attributes)) {
            $provider = array_key_exists('calendar_provider', $attributes) ? $attributes['calendar_provider'] : $task->calendar_provider;
            $calendarId = array_key_exists('calendar_id', $attributes) ? $attributes['calendar_id'] : $task->calendar_id;
            $eventId = array_key_exists('calendar_event_id', $attributes) ? $attributes['calendar_event_id'] : $task->calendar_event_id;
            $this->assertCalendarReference(
                is_string($provider) ? $provider : null,
                is_string($calendarId) ? $calendarId : null,
                is_string($eventId) ? $eventId : null,
            );
            $task->calendar_provider = is_string($provider) && $provider !== '' ? $provider : null;
            $task->calendar_id = is_string($calendarId) && $calendarId !== '' ? $calendarId : null;
            $task->calendar_event_id = is_string($eventId) && $eventId !== '' ? $eventId : null;
        }

        $task->save();
        $fresh = $task->fresh() ?? $task;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function startOwned(User $user, int $taskId): Task
    {
        $task = $this->requireOwned($user, $taskId);
        TaskLifecycle::markStarted($task);
        $task->save();
        $fresh = $task->fresh() ?? $task;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function completeOwned(User $user, int $taskId, bool $force = false): Task
    {
        $task = $this->requireOwned($user, $taskId);
        $children = $this->childrenOf($task);
        $reminders = $this->openLinkedReminders($task);
        $now = CarbonImmutable::now('UTC');
        $cancelled = TaskLifecycle::markCompleted($task, $now, $children, $reminders, $force);

        foreach ($cancelled as $reminder) {
            if ($reminder->exists) {
                $reminder->save();
            }
        }

        $task->save();

        $fresh = $task->fresh(['reminders', 'subtasks', 'project', 'sourceConversation']) ?? $task;
        $this->knowledge->taskCompleted($fresh);
        $this->notifyWatchers($fresh);

        try {
            app(CommitmentLifecycle::class)->fulfillLinked($user, $fresh);
        } catch (Throwable) {
        }

        return $fresh;
    }

    public function cancelOwned(User $user, int $taskId): Task
    {
        $task = $this->requireOwned($user, $taskId);
        $reminders = $this->openLinkedReminders($task);
        $now = CarbonImmutable::now('UTC');
        TaskLifecycle::markCancelled($task, $now, $reminders);

        foreach ($reminders as $reminder) {
            if ($reminder->exists) {
                $reminder->save();
            }
        }

        $task->save();

        $fresh = $task->fresh(['reminders', 'subtasks', 'project', 'sourceConversation']) ?? $task;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function reopenOwned(User $user, int $taskId): Task
    {
        $task = $this->requireOwned($user, $taskId);
        TaskLifecycle::markReopened($task);
        $task->save();
        $fresh = $task->fresh() ?? $task;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function addSubtask(User $user, int $parentId, string $title, ?string $description = null): Task
    {
        $parent = $this->requireOwned($user, $parentId);

        if ($parent->parent_task_id !== null) {
            throw new TaskException('nested_subtask', 'Subtasks cannot have their own subtasks.');
        }

        if (! TaskLifecycle::isOpen($parent)) {
            throw new TaskException('not_editable', 'Subtasks can only be added to open tasks.');
        }

        return $this->create(
            user: $user,
            title: $title,
            description: $description,
            parentTaskId: (int) $parent->id,
            timezone: $parent->timezone,
        );
    }

    public function findOwned(User $user, int $taskId): ?Task
    {
        return Task::query()
            ->where('user_id', $user->id)
            ->whereKey($taskId)
            ->first();
    }

    public function requireOwned(User $user, int $taskId): Task
    {
        $task = $this->findOwned($user, $taskId);

        if ($task === null) {
            throw new TaskException('not_found', 'Task was not found.');
        }

        return $task;
    }

    /**
     * @return list<Task>
     */
    public function candidatesForMutation(User $user): array
    {
        $this->assertCanUse($user);

        return Task::query()
            ->where('user_id', $user->id)
            ->whereIn('status', TaskLifecycle::openStatuses())
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->all();
    }

    /**
     * @return Collection<int, Task>
     */
    public function searchOwned(User $user, ?string $query = null, int $limit = 20): Collection
    {
        $this->assertCanUse($user);
        $limit = max(1, min(40, $limit));
        $builder = Task::query()
            ->with(['reminders', 'subtasks', 'project:id,user_id,name', 'sourceConversation:id,user_id,title'])
            ->where('user_id', $user->id)
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->limit($limit);

        $needle = trim((string) $query);

        if ($needle !== '') {
            $builder->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%');
            });
        }

        return $builder->get();
    }

    public function activeOpenCount(User $user): int
    {
        if (! $user->canUseCapability(UserCapability::TASKS)) {
            return 0;
        }

        return $this->withoutOpenParent(
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('status', TaskLifecycle::openStatuses())
        )->count();
    }

    /**
     * Top-level work is everything that is not already listed inside another open task's card.
     *
     * A subtask of a closed parent has no card to live in, so it counts as work of its own —
     * otherwise completing a parent silently hides whatever is still open under it.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function withoutOpenParent(Builder $query): Builder
    {
        return $query->where(function ($outer): void {
            $outer->whereNull('parent_task_id')
                ->orWhereHas('parent', function ($parent): void {
                    $parent->whereNotIn('status', TaskLifecycle::openStatuses());
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function panelFor(User $user): array
    {
        $this->assertCanUse($user);
        $timezone = (string) ($user->timezone ?: 'UTC');
        $now = CarbonImmutable::now('UTC');
        $includeProjects = $user->canUseCapability(UserCapability::PROJECTS);

        $open = $this->withoutOpenParent(
            Task::query()
                ->with([
                    'reminders',
                    'subtasks',
                    'parent:id,user_id,title',
                    'project:id,user_id,name',
                    'sourceConversation:id,user_id,title',
                ])
                ->where('user_id', $user->id)
                ->whereIn('status', TaskLifecycle::openStatuses())
        )
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->limit(80)
            ->get();

        $done = Task::query()
            ->with(['reminders', 'subtasks', 'parent:id,user_id,title', 'project:id,user_id,name', 'sourceConversation:id,user_id,title'])
            ->where('user_id', $user->id)
            ->where('status', TaskStatus::Completed)
            ->whereNull('parent_task_id')
            ->orderByDesc('completed_at')
            ->limit(30)
            ->get();

        $today = [];
        $overdue = [];
        $upcoming = [];
        $undated = [];
        $inProgress = [];

        foreach ($open as $task) {
            $row = $this->serializeForPanel($task, $timezone, $user, $includeProjects, $now);

            if ($row['status'] === TaskStatus::InProgress->value) {
                $inProgress[] = $row;
            }

            if ($row['is_overdue']) {
                $overdue[] = $row;

                continue;
            }

            if ($row['due_at'] === null) {
                $undated[] = $row;

                continue;
            }

            if ($row['is_due_today']) {
                $today[] = $row;

                continue;
            }

            $upcoming[] = $row;
        }

        $projects = [];

        if ($includeProjects) {
            $projects = Project::query()
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->limit(40)
                ->get(['id', 'name'])
                ->map(static fn (Project $project): array => [
                    'id' => (int) $project->id,
                    'name' => (string) $project->name,
                ])
                ->values()
                ->all();
        }

        return [
            'timezone' => $timezone,
            'can_use_projects' => $includeProjects,
            'active_count' => $this->activeOpenCount($user),
            'today' => $today,
            'overdue' => $overdue,
            'upcoming' => $upcoming,
            'undated' => $undated,
            'in_progress' => $inProgress,
            'completed' => $done->map(fn (Task $task): array => $this->serializeForPanel($task, $timezone, $user, $includeProjects, $now))->values()->all(),
            'projects' => $projects,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeForPanel(Task $task, string $fallbackTimezone, User $user, bool $includeProjects, CarbonImmutable $now): array
    {
        $timezone = (string) ($task->timezone ?: $fallbackTimezone);

        try {
            $local = $task->due_at?->setTimezone($timezone);
        } catch (Exception) {
            $local = $task->due_at?->utc();
            $timezone = 'UTC';
        }

        $isOverdue = TaskLifecycle::isOpen($task)
            && $task->due_at !== null
            && $task->due_at->utc()->lessThan($now->utc());
        $isDueToday = TaskLifecycle::isOpen($task)
            && $task->due_at !== null
            && ! $isOverdue
            && $this->isLocalToday($task->due_at, $timezone, $now);

        $source = null;

        if ($task->sourceConversation !== null && (int) $task->sourceConversation->user_id === (int) $user->id) {
            $source = [
                'id' => (int) $task->sourceConversation->id,
                'title' => (string) $task->sourceConversation->title,
            ];
        }

        $project = null;

        if ($includeProjects && $task->project !== null && (int) $task->project->user_id === (int) $user->id) {
            $project = [
                'id' => (int) $task->project->id,
                'name' => (string) $task->project->name,
            ];
        }

        $reminders = [];

        foreach ($task->reminders as $reminder) {
            if (! $reminder instanceof Reminder || (int) $reminder->user_id !== (int) $user->id) {
                continue;
            }

            $reminders[] = [
                'id' => (int) $reminder->id,
                'text' => (string) $reminder->text,
                'status' => $reminder->status->value,
                'run_at' => optional($reminder->run_at)?->toIso8601String(),
                'open' => ReminderLifecycle::isOpen($reminder),
            ];
        }

        $subtasks = [];

        foreach ($task->subtasks as $child) {
            if ((int) $child->user_id !== (int) $user->id) {
                continue;
            }

            $childTimezone = (string) ($child->timezone ?: $timezone);
            $subtasks[] = [
                'id' => (int) $child->id,
                'title' => (string) $child->title,
                'status' => $child->status->value,
                'open' => TaskLifecycle::isOpen($child),
                'status_label' => HumanStatusLabel::taskStatus($child->status),
                'due_label' => HumanMoment::label($child->due_at, $childTimezone, $now),
                'startable' => TaskLifecycle::isStartable($child),
                'completable' => TaskLifecycle::isCompletable($child),
                'cancellable' => TaskLifecycle::isCancellable($child),
                'editable' => TaskLifecycle::isOpen($child),
            ];
        }

        $openSubtasks = array_values(array_filter($subtasks, static fn (array $row): bool => $row['open']));
        $dueLabel = HumanMoment::label($task->due_at, $timezone, $now);

        return [
            'id' => (int) $task->id,
            'title' => (string) $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => optional($task->due_at)?->toIso8601String(),
            'due_at_local' => $local?->format('Y-m-d\TH:i:sP'),
            'timezone' => $timezone,
            'is_overdue' => $isOverdue,
            'is_due_today' => $isDueToday,
            'startable' => TaskLifecycle::isStartable($task),
            'completable' => TaskLifecycle::isCompletable($task),
            'cancellable' => TaskLifecycle::isCancellable($task),
            'editable' => TaskLifecycle::isOpen($task),
            'reopenable' => TaskLifecycle::isReopenable($task),
            'source_conversation' => $source,
            'project' => $project,
            'calendar' => $task->calendar_event_id ? [
                'provider' => $task->calendar_provider,
                'calendar_id' => $task->calendar_id,
                'event_id' => $task->calendar_event_id,
            ] : null,
            'reminders' => $reminders,
            'reminder_count' => count($reminders),
            'subtasks' => $subtasks,
            'subtask_count' => count($subtasks),
            'open_subtask_count' => count($openSubtasks),
            'open_subtask_titles' => array_slice(array_map(
                static fn (array $row): string => $row['title'],
                $openSubtasks,
            ), 0, 5),
            'due_label' => $dueLabel,
            'schedule_label' => $dueLabel ?? 'Без срока',
            // Only the panel eager-loads the parent; nothing here is worth an extra query per row.
            'parent_label' => $task->relationLoaded('parent') && $task->parent !== null && (int) $task->parent->user_id === (int) $user->id
                ? 'Подзадача задачи «'.$task->parent->title.'»'
                : null,
            'priority_label' => HumanStatusLabel::taskPriority($task->priority),
            'status_label' => HumanStatusLabel::taskStatus($task->status),
            'state_label' => HumanStatusLabel::activeTaskStatus($task->status),
            'subtask_progress_label' => HumanStatusLabel::subtaskProgress(
                count($subtasks) - count($openSubtasks),
                count($subtasks),
            ),
            'completed_at' => optional($task->completed_at)?->toIso8601String(),
            'completed_label' => HumanMoment::label($task->completed_at, $timezone, $now),
            'cancelled_at' => optional($task->cancelled_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array{overdue: int, due_today: int, urgent: list<array{id: int, title: string, priority: string, due_at: ?string}>}
     */
    public function snapshotFor(User $user, int $urgentLimit = 3): array
    {
        if (! $user->canUseCapability(UserCapability::TASKS)) {
            return ['overdue' => 0, 'due_today' => 0, 'urgent' => []];
        }

        $timezone = (string) ($user->timezone ?: 'UTC');
        $now = CarbonImmutable::now('UTC');
        $open = Task::query()
            ->where('user_id', $user->id)
            ->whereIn('status', TaskLifecycle::openStatuses())
            ->whereNull('parent_task_id')
            ->orderByRaw("case when priority = 'urgent' then 0 when priority = 'high' then 1 else 2 end")
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->limit(40)
            ->get();

        $overdue = 0;
        $dueToday = 0;
        $urgent = [];

        foreach ($open as $task) {
            $isOverdue = $task->due_at !== null && $task->due_at->utc()->lessThan($now->utc());
            $isDueToday = $task->due_at !== null && ! $isOverdue && $this->isLocalToday($task->due_at, (string) ($task->timezone ?: $timezone), $now);

            if ($isOverdue) {
                $overdue++;
            }

            if ($isDueToday) {
                $dueToday++;
            }

            if (count($urgent) < $urgentLimit && in_array($task->priority, [TaskPriority::Urgent, TaskPriority::High], true)) {
                $urgent[] = [
                    'id' => (int) $task->id,
                    'title' => (string) $task->title,
                    'priority' => $task->priority->value,
                    'due_at' => optional($task->due_at)?->toIso8601String(),
                ];
            }
        }

        return [
            'overdue' => $overdue,
            'due_today' => $dueToday,
            'urgent' => $urgent,
        ];
    }

    public function localWallTimeToUtc(string $runAtLocal, string $timezone): CarbonImmutable
    {
        if (! $this->isValidTimezone($timezone)) {
            throw new TaskException('invalid_timezone', 'Timezone is invalid.');
        }

        try {
            $parsed = CarbonImmutable::parse($runAtLocal, $timezone);
        } catch (Exception) {
            throw new TaskException('invalid_time', 'Due date is invalid.');
        }

        return $parsed->utc();
    }

    public function normalizePriority(mixed $value): TaskPriority
    {
        if ($value instanceof TaskPriority) {
            return $value;
        }

        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        return TaskPriority::tryFrom($raw)
            ?? throw new TaskException('invalid_priority', 'Priority is invalid.');
    }

    private function notifyWatchers(Task $task): void
    {
        try {
            app(WatcherEvaluationDispatcher::class)->afterTaskChanged($task);
        } catch (Throwable) {
        }
    }

    private function assertCanUse(User $user): void
    {
        if (! $user->canUseCapability(UserCapability::TASKS)) {
            throw new TaskException('capability_denied', 'Tasks are not available.');
        }

        if (! $user->isActive()) {
            throw new TaskException('user_inactive', 'User is not active.');
        }
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        $max = max(1, (int) config('productivity.tasks.title_max', 240));

        if ($title === '') {
            throw new TaskException('empty_title', 'Task title is empty.');
        }

        if (Str::length($title) > $max) {
            throw new TaskException('title_too_long', 'Task title is too long.');
        }

        return $title;
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim($description);

        if ($description === '') {
            return null;
        }

        $max = max(1, (int) config('productivity.tasks.description_max', 4000));

        if (Str::length($description) > $max) {
            throw new TaskException('description_too_long', 'Task description is too long.');
        }

        return $description;
    }

    private function normalizeTimezone(string $timezone): string
    {
        if (! $this->isValidTimezone($timezone)) {
            throw new TaskException('invalid_timezone', 'Timezone is invalid.');
        }

        return $timezone;
    }

    private function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return false;
        }

        return true;
    }

    private function ownedConversation(User $user, ?Conversation $conversation): ?Conversation
    {
        if ($conversation === null) {
            return null;
        }

        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new TaskException('not_found', 'Conversation was not found.');
        }

        return $conversation;
    }

    private function ownedMessage(User $user, ?Conversation $conversation, ?Message $message): ?Message
    {
        if ($message === null) {
            return null;
        }

        if ($conversation === null || (int) $message->conversation_id !== (int) $conversation->id) {
            return null;
        }

        if ((int) ($message->user_id ?? $conversation->user_id) !== (int) $user->id && (int) $conversation->user_id !== (int) $user->id) {
            return null;
        }

        return $message;
    }

    private function ownedProjectId(User $user, ?int $projectId): ?int
    {
        if ($projectId === null || $projectId <= 0) {
            return null;
        }

        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            throw new TaskException('project_not_allowed', 'Projects are not available.');
        }

        $exists = Project::query()
            ->where('user_id', $user->id)
            ->whereKey($projectId)
            ->exists();

        if (! $exists) {
            throw new TaskException('not_found', 'Project was not found.');
        }

        return $projectId;
    }

    private function assertCalendarReference(?string $provider, ?string $calendarId, ?string $eventId): void
    {
        $parts = array_filter([$provider, $calendarId, $eventId], static fn (?string $value): bool => filled($value));

        if ($parts === []) {
            return;
        }

        if (count($parts) !== 3) {
            throw new TaskException('invalid_calendar', 'Calendar reference needs provider, calendar_id, and event_id.');
        }
    }

    /**
     * @return list<Task>
     */
    private function childrenOf(Task $task): array
    {
        if (! $task->exists) {
            return $task->relationLoaded('subtasks') ? $task->subtasks->all() : [];
        }

        return Task::query()
            ->where('user_id', $task->user_id)
            ->where('parent_task_id', $task->id)
            ->get()
            ->all();
    }

    /**
     * @return list<Reminder>
     */
    private function openLinkedReminders(Task $task): array
    {
        if (! $task->exists) {
            return [];
        }

        return Reminder::query()
            ->where('user_id', $task->user_id)
            ->where('task_id', $task->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->get()
            ->all();
    }

    private function isLocalToday(CarbonImmutable $utc, string $timezone, CarbonImmutable $now): bool
    {
        try {
            $localDue = $utc->utc()->setTimezone($timezone);
            $localNow = $now->utc()->setTimezone($timezone);
        } catch (Exception) {
            $localDue = $utc->utc();
            $localNow = $now->utc();
        }

        return $localDue->toDateString() === $localNow->toDateString();
    }
}
