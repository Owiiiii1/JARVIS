<?php

namespace App\Services\Watchers;

use App\Enums\KnowledgeEventType;
use App\Enums\WatcherStatus;
use App\Enums\WatcherTriggerType;
use App\Jobs\EvaluateWatcherJob;
use App\Models\KnowledgeEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Synthesis\SynthesisCache;

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

    private function bumpSynthesis(int $userId): void
    {
        try {
            app(SynthesisCache::class)->bumpUserId($userId);
        } catch (\Throwable) {
        }
    }
}
