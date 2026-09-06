<?php

namespace App\Services\Synthesis;

use App\Enums\TaskStatus;
use App\Models\ConversationSummary;
use App\Models\KnowledgeEvent;
use App\Models\Task;
use App\Models\WatcherOccurrence;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Synthesis\DTO\SynthesisItem;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;

final class SynthesisChangeAssembler
{
    public function __construct(
        private readonly SynthesisDeduplicator $dedupe = new SynthesisDeduplicator,
    ) {}

    /**
     * @return list<SynthesisItem>
     */
    public function recent(FactPack $pack): array
    {
        $items = [];

        foreach ($pack->events as $event) {
            if (! $event instanceof KnowledgeEvent) {
                continue;
            }

            if ($event->occurred_at instanceof CarbonImmutable && $event->occurred_at->lessThan($pack->windowStart)) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'change',
                title: $event->title,
                why: $event->type->value,
                since: optional($event->occurred_at)?->toIso8601String(),
                score: 20,
                sources: [new SourceRef(
                    knowledgeEventId: (int) $event->id,
                    conversationId: $event->conversation_id,
                    sourceFingerprint: $event->source_fingerprint,
                    domain: 'knowledge',
                )],
                extra: ['event_type' => $event->type->value],
                fingerprint: $this->dedupe->eventKey($event),
            );
        }

        foreach ($pack->occurrences as $occurrence) {
            if (! $occurrence instanceof WatcherOccurrence) {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'change',
                title: 'Watcher triggered',
                why: 'watcher_occurrence',
                since: optional($occurrence->detected_at)?->toIso8601String(),
                score: 45,
                sources: [new SourceRef(
                    watcherId: (int) $occurrence->watcher_id,
                    occurrenceId: (int) $occurrence->id,
                    knowledgeEventId: $occurrence->knowledge_event_id ? (int) $occurrence->knowledge_event_id : null,
                    sourceFingerprint: $occurrence->trigger_fingerprint,
                    domain: 'watcher',
                )],
                fingerprint: $this->dedupe->occurrenceKey($occurrence),
            );
        }

        foreach ($pack->tasks as $task) {
            if (! $task instanceof Task) {
                continue;
            }

            $changed = $task->updated_at ? CarbonImmutable::parse((string) $task->updated_at) : null;

            if ($changed === null || $changed->lessThan($pack->windowStart)) {
                continue;
            }

            $label = $task->status === TaskStatus::Completed ? 'Completed: '.$task->title : 'Task updated: '.$task->title;
            $items[] = new SynthesisItem(
                kind: 'change',
                title: $label,
                why: 'task_'.$task->status->value,
                since: $changed->toIso8601String(),
                score: $task->status === TaskStatus::Completed ? 25 : 15,
                sources: [new SourceRef(taskId: (int) $task->id, projectId: $task->project_id, domain: 'task')],
                fingerprint: 'task:'.$task->id.':'.$task->status->value.':'.$changed->toDateString(),
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
                title: 'Conversation summary updated',
                why: 'conversation_summary',
                since: $updated->toIso8601String(),
                score: 10,
                sources: [new SourceRef(conversationId: (int) $summary->conversation_id, domain: 'conversation')],
                fingerprint: 'summary:'.$summary->conversation_id.':'.$updated->toDateString(),
            );
        }

        return $this->dedupe->items($items);
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

            $items[] = new SynthesisItem(
                kind: 'task',
                title: $task->title,
                why: $task->status->value,
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
     * @return list<SynthesisItem>
     */
    public function upcoming(FactPack $pack): array
    {
        $items = [];

        foreach ($pack->reminders as $reminder) {
            $items[] = new SynthesisItem(
                kind: 'reminder',
                title: (string) $reminder->text,
                dueAt: optional($reminder->run_at)?->toIso8601String(),
                score: 15,
                sources: [new SourceRef(reminderId: (int) $reminder->id, taskId: $reminder->task_id, domain: 'reminder')],
                fingerprint: 'reminder:'.$reminder->id,
            );
        }

        foreach ($pack->watchers as $watcher) {
            if ($watcher->status->value !== 'active') {
                continue;
            }

            $items[] = new SynthesisItem(
                kind: 'watcher',
                title: $watcher->name,
                why: $watcher->health->value,
                sources: [new SourceRef(watcherId: (int) $watcher->id, projectId: $watcher->project_id, domain: 'watcher')],
                extra: ['mode' => $watcher->mode->value],
                fingerprint: 'watcher:'.$watcher->id,
            );
        }

        return $items;
    }
}
