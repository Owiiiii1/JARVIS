<?php

namespace App\Services\Synthesis;

use App\Enums\KnowledgeEventType;
use App\Enums\TaskStatus;
use App\Enums\WatcherHealth;
use App\Enums\WatcherMode;
use App\Enums\WatcherStatus;
use App\Models\ConversationSummary;
use App\Models\KnowledgeEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Tasks\TaskLifecycle;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanStatusLabel;
use App\Services\Workspace\Presentation\HumanSynthesisText;
use App\Services\Workspace\Presentation\HumanWatcherDescription;
use Carbon\CarbonImmutable;

final class SynthesisChangeAssembler
{
    /**
     * Task lifecycle events also exist as canonical task rows; one completion is one card.
     */
    private const TASK_EVENT_TYPES = [
        KnowledgeEventType::TaskCreated->value,
        KnowledgeEventType::TaskCompleted->value,
    ];

    public function __construct(
        private readonly SynthesisDeduplicator $dedupe = new SynthesisDeduplicator,
        private readonly WaitingForResolver $waiting = new WaitingForResolver,
    ) {}

    /**
     * @return list<SynthesisItem>
     */
    public function recent(FactPack $pack): array
    {
        $items = [];
        $tasksById = $this->tasksById($pack);

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task) {
                continue;
            }

            $changed = $task->updated_at ? CarbonImmutable::parse((string) $task->updated_at) : null;

            if ($changed === null || $changed->lessThan($pack->windowStart)) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'change',
                title: HumanSynthesisText::taskChange($task),
                since: $changed->toIso8601String(),
                score: $task->status === TaskStatus::Completed ? 25 : 15,
                sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                extra: ['event_type' => 'task_'.$task->status->value],
                fingerprint: $this->taskChangeKey((int) $task->id, $task->status),
            );
        }

        foreach ($pack->events as $event) {
            if (! $event instanceof KnowledgeEvent) {
                continue;
            }

            if ($event->occurred_at instanceof CarbonImmutable && $event->occurred_at->lessThan($pack->windowStart)) {
                continue;
            }

            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $taskId = (int) ($metadata['task_id'] ?? 0);
            $task = $taskId > 0 ? ($tasksById[$taskId] ?? null) : null;
            $isTaskEvent = in_array($event->type->value, self::TASK_EVENT_TYPES, true);

            $items[] = new SynthesisItem(
                kind: 'change',
                title: HumanSynthesisText::knowledgeEvent($event, $task instanceof Task ? (string) $task->title : null),
                since: optional($event->occurred_at)?->toIso8601String(),
                score: 20,
                sources: [new SourceRef(
                    knowledgeEventId: (int) $event->id,
                    conversationId: $event->conversation_id,
                    taskId: $taskId > 0 ? $taskId : null,
                    sourceFingerprint: $event->source_fingerprint,
                    domain: 'knowledge',
                )],
                extra: ['event_type' => $event->type->value],
                fingerprint: $isTaskEvent && $task instanceof Task
                    ? $this->taskChangeKey($taskId, $task->status)
                    : $this->dedupe->eventKey($event),
            );
        }

        foreach ($pack->occurrences as $occurrence) {
            if (! $occurrence instanceof WatcherOccurrence) {
                continue;
            }

            $watcher = $occurrence->relationLoaded('watcher') ? $occurrence->watcher : null;
            $items[] = new SynthesisItem(
                kind: 'change',
                title: HumanSynthesisText::watcherTriggered($watcher instanceof Watcher ? $watcher->name : null),
                since: optional($occurrence->detected_at)?->toIso8601String(),
                score: 45,
                sources: [new SourceRef(
                    watcherId: (int) $occurrence->watcher_id,
                    occurrenceId: (int) $occurrence->id,
                    knowledgeEventId: $occurrence->knowledge_event_id ? (int) $occurrence->knowledge_event_id : null,
                    sourceFingerprint: $occurrence->trigger_fingerprint,
                    domain: 'watcher',
                )],
                extra: ['event_type' => 'watcher_occurrence'],
                fingerprint: $this->dedupe->occurrenceKey($occurrence),
            );
        }

        foreach ($pack->summaries as $summary) {
            if (! $summary instanceof ConversationSummary) {
                continue;
            }

            $updated = $summary->updated_at ? CarbonImmutable::parse((string) $summary->updated_at) : null;

            if ($updated === null || $updated->lessThan($pack->windowStart)) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'change',
                title: 'Обновлён итог переписки',
                since: $updated->toIso8601String(),
                score: 10,
                sources: [new SourceRef(conversationId: (int) $summary->conversation_id, domain: 'conversation')],
                extra: ['event_type' => 'conversation_summary'],
                fingerprint: 'summary:'.$summary->conversation_id.':'.$updated->toDateString(),
            );
        }

        return $this->dedupe->changes($items);
    }

    /**
     * @return list<SynthesisItem>
     */
    public function openWork(FactPack $pack): array
    {
        $items = [];

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task || ! TaskLifecycle::isOpen($task)) {
                continue;
            }

            $due = HumanMoment::label($task->due_at, $pack->timezone, $pack->now);
            $items[] = new SynthesisItem(
                kind: 'task',
                title: (string) $task->title,
                why: $due !== null ? 'Срок: '.mb_strtolower($due) : HumanStatusLabel::activeTaskStatus($task->status),
                dueAt: optional($task->due_at)?->toIso8601String(),
                score: $task->priority->value === 'urgent' ? 40 : ($task->priority->value === 'high' ? 30 : 10),
                sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                extra: ['priority' => $task->priority->value, 'status' => $task->status->value],
                fingerprint: 'task:'.$task->id,
            );
        }

        return $items;
    }

    /**
     * The near-term agenda: open reminders, tasks due soon, and scheduled checks.
     *
     * Everything here is filtered against canonical state — a cancelled reminder, a closed task
     * or a watcher that can no longer fire is not part of anybody's day.
     *
     * @return list<SynthesisItem>
     */
    public function upcoming(FactPack $pack): array
    {
        $items = [];
        $horizon = $pack->now->addDays(2);
        $tasksById = $this->tasksById($pack);
        $remindedTaskIds = [];

        foreach ($pack->reminders as $reminder) {
            if (! $reminder instanceof Reminder || ! ReminderLifecycle::isOpen($reminder)) {
                continue;
            }

            $linked = $reminder->task_id !== null ? ($tasksById[(int) $reminder->task_id] ?? null) : null;

            if ($linked instanceof Task && ! TaskLifecycle::isOpen($linked)) {
                continue;
            }

            if ($reminder->task_id !== null) {
                $remindedTaskIds[(int) $reminder->task_id] = true;
            }

            $items[] = new SynthesisItem(
                kind: 'reminder',
                title: (string) $reminder->text,
                why: HumanMoment::label($reminder->run_at, $pack->timezone, $pack->now),
                dueAt: optional($reminder->run_at)?->toIso8601String(),
                score: 15,
                sources: [new SourceRef(reminderId: (int) $reminder->id, taskId: $reminder->task_id, domain: 'reminder')],
                extra: ['event_type' => 'reminder'],
                fingerprint: 'reminder:'.$reminder->id,
            );
        }

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task || ! TaskLifecycle::isOpen($task) || $task->due_at === null) {
                continue;
            }

            if ($task->due_at->utc()->greaterThan($horizon)) {
                continue;
            }

            // A reminder for this task already occupies the agenda; two rows for one thing to do
            // is the duplication the Overview is being fixed for.
            if (isset($remindedTaskIds[(int) $task->id])) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'task',
                title: (string) $task->title,
                why: HumanMoment::label($task->due_at, $pack->timezone, $pack->now),
                dueAt: $task->due_at->toIso8601String(),
                score: 25,
                sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                extra: ['event_type' => 'task_due'],
                fingerprint: 'upcoming:task:'.$task->id,
            );
        }

        $stale = $this->waiting->staleWatcherIds($pack->watchers);

        foreach ($pack->watchers as $watcher) {
            if (! $watcher instanceof Watcher || $watcher->status !== WatcherStatus::Active) {
                continue;
            }

            if (isset($stale[(int) $watcher->id])) {
                continue;
            }

            // Anything the user is already "waiting" on belongs to that section, not to the agenda.
            if ($watcher->mode === WatcherMode::OneShot || $watcher->health === WatcherHealth::Waiting) {
                continue;
            }

            if ($watcher->next_check_at === null || $watcher->next_check_at->utc()->greaterThan($horizon)) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'watcher',
                title: HumanWatcherDescription::sentence($watcher, [], $pack->timezone),
                why: HumanStatusLabel::watcherState($watcher, $pack->timezone),
                dueAt: $watcher->next_check_at->toIso8601String(),
                score: 12,
                sources: [new SourceRef(watcherId: (int) $watcher->id, projectId: $watcher->project_id, domain: 'watcher')],
                extra: ['event_type' => 'watcher_check', 'mode' => $watcher->mode->value],
                fingerprint: 'watcher:'.$watcher->id,
            );
        }

        return $this->dedupe->items($items);
    }

    /**
     * @return array<int, Task>
     */
    private function tasksById(FactPack $pack): array
    {
        $tasks = [];

        foreach ($pack->tasks as $task) {
            if ($task instanceof Task) {
                $tasks[(int) $task->id] = $task;
            }
        }

        return $tasks;
    }

    private function taskChangeKey(int $taskId, TaskStatus $status): string
    {
        return 'task-change:'.$taskId.':'.$status->value;
    }
}
