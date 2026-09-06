<?php

namespace App\Services\Watchers;

use App\Enums\KnowledgeEventType;
use App\Enums\WatcherConditionType;
use App\Enums\WatcherHealth;
use App\Enums\WatcherMode;
use App\Enums\WatcherStatus;
use App\Enums\WatcherTriggerType;
use App\Jobs\EvaluateWatcherJob;
use App\Models\KnowledgeEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Synthesis\SynthesisCache;
use App\Services\Tasks\TaskLifecycle;

final class WatcherEvaluationDispatcher
{
    public function afterKnowledgeEvent(User $user, KnowledgeEvent $event): void
    {
        if ($event->type === KnowledgeEventType::WatcherTriggered) {
            return;
        }

        $query = Watcher::query()
            ->where('user_id', $user->id)
            ->where('status', WatcherStatus::Active)
            ->where('trigger_type', WatcherTriggerType::KnowledgeEvent);

        $ids = $event->entities()->pluck('knowledge_entities.id')->all();
        if ($ids !== []) {
            $query->where(function ($inner) use ($ids): void {
                $inner->whereNull('knowledge_entity_id')->orWhereIn('knowledge_entity_id', $ids);
            });
        }

        foreach ($query->orderBy('id')->limit(40)->get() as $watcher) {
            EvaluateWatcherJob::dispatch((int) $watcher->id);
        }

        $this->bumpSynthesis((int) $user->id);
    }

    public function afterTaskChanged(Task $task): void
    {
        $watchers = Watcher::query()
            ->where('user_id', $task->user_id)
            ->where('status', WatcherStatus::Active)
            ->where('task_id', $task->id)
            ->whereIn('trigger_type', [WatcherTriggerType::TaskState, WatcherTriggerType::TimeCondition])
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($watchers as $watcher) {
            if ($this->resolveWhenTaskClosed($task, $watcher)) {
                continue;
            }

            EvaluateWatcherJob::dispatch((int) $watcher->id);
        }

        $this->bumpSynthesis((int) $task->user_id);
    }

    public function afterReminderChanged(Reminder $reminder): void
    {
        $watchers = Watcher::query()
            ->where('user_id', $reminder->user_id)
            ->where('status', WatcherStatus::Active)
            ->where('reminder_id', $reminder->id)
            ->where('trigger_type', WatcherTriggerType::ReminderState)
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($watchers as $watcher) {
            EvaluateWatcherJob::dispatch((int) $watcher->id);
        }

        $this->bumpSynthesis((int) $reminder->user_id);
    }

    /**
     * A one-shot watcher whose condition can only match while the task is open never fires once the
     * task is closed, so it is finished here instead of staying Active as a permanent waiting-for item.
     */
    private function resolveWhenTaskClosed(Task $task, Watcher $watcher): bool
    {
        if ($watcher->mode !== WatcherMode::OneShot || TaskLifecycle::isOpen($task)) {
            return false;
        }

        if (! $this->requiresOpenTask($watcher)) {
            return false;
        }

        $watcher->forceFill([
            'status' => WatcherStatus::Completed,
            'health' => WatcherHealth::Healthy,
            'cursor' => array_merge(is_array($watcher->cursor) ? $watcher->cursor : [], [
                'resolved_reason' => 'task_closed',
            ]),
        ])->save();

        return true;
    }

    private function requiresOpenTask(Watcher $watcher): bool
    {
        return match ($watcher->condition_type) {
            WatcherConditionType::OverdueBy, WatcherConditionType::DeadlineWithin => true,
            WatcherConditionType::StatusEquals => in_array(
                mb_strtolower((string) ($watcher->condition_config['status'] ?? $watcher->condition_config['expected'] ?? '')),
                ['open', 'in_progress'],
                true,
            ),
            default => false,
        };
    }

    private function bumpSynthesis(int $userId): void
    {
        try {
            app(SynthesisCache::class)->bumpUserId($userId);
        } catch (\Throwable) {
        }
    }
}
