<?php

namespace App\Services\Productivity;

use App\Enums\JarvisNotificationType;
use App\Models\JarvisNotification;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Tasks\TaskLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class ProactiveDispatchService
{
    public function __construct(
        private readonly ProactivePolicy $policy = new ProactivePolicy,
        private readonly ProactiveTriggerDetector $triggers = new ProactiveTriggerDetector,
        private readonly JarvisNotificationService $inbox = new JarvisNotificationService,
        private readonly NotificationUrlPolicy $urls = new NotificationUrlPolicy,
        private readonly ?SynthesizesProductivityBrief $phrasing = null,
    ) {}

    public function dispatchDue(int $limit = 80): int
    {
        if (! Schema::hasTable('user_productivity_settings')) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $created = 0;
        $settingsRows = UserProductivitySetting::query()
            ->with('user')
            ->where('proactive_enabled', true)
            ->limit(max(1, $limit))
            ->get();

        foreach ($settingsRows as $settings) {
            $user = $settings->user;

            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            $todayCount = $this->todayCount($user, $now);
            $tasks = Task::query()
                ->where('user_id', $user->id)
                ->whereIn('status', TaskLifecycle::openStatuses())
                ->whereNotNull('due_at')
                ->orderBy('due_at')
                ->limit(40)
                ->get();

            foreach ($tasks as $task) {
                foreach ($this->triggers->forTask($task, $now) as $event) {
                    $existing = JarvisNotification::query()
                        ->where('user_id', $user->id)
                        ->where('dedupe_key', $event['dedupe_key'])
                        ->first();
                    $lastSame = JarvisNotification::query()
                        ->where('user_id', $user->id)
                        ->where('type', JarvisNotificationType::ProactiveSuggestion)
                        ->where('source_type', 'task')
                        ->where('source_id', $event['source_id'])
                        ->orderByDesc('occurred_at')
                        ->first();

                    if (! $this->policy->mayEmit(
                        $settings,
                        $todayCount,
                        $lastSame?->occurred_at,
                        $now,
                        $existing !== null,
                    )) {
                        continue;
                    }

                    $body = $event['body'];
                    $aiUsed = false;

                    if ($this->phrasing !== null) {
                        $phrased = $this->phrasing->synthesize($user, 'proactive', $body, [
                            'trigger' => $event['trigger'],
                            'title' => $task->title,
                        ]);

                        if (is_string($phrased) && trim($phrased) !== '') {
                            $body = trim($phrased);
                            $aiUsed = true;
                        }
                    }

                    $row = $this->inbox->record(
                        $user,
                        JarvisNotificationType::ProactiveSuggestion,
                        $event['title'],
                        $body,
                        $event['dedupe_key'],
                        'task',
                        $event['source_id'],
                        $this->urls->workspacePath($user, 'task='.$task->id, $task->source_conversation_id ? (int) $task->source_conversation_id : null),
                        [
                            'trigger' => $event['trigger'],
                            'source_id' => $event['source_id'],
                            'ai_phrased' => $aiUsed,
                        ],
                        $aiUsed,
                    );

                    if ($row !== null) {
                        $created++;
                        $todayCount++;
                    }
                }
            }
        }

        return $created;
    }

    private function todayCount(User $user, CarbonImmutable $now): int
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $start = $now->utc()->setTimezone($timezone)->startOfDay()->utc();

        return JarvisNotification::query()
            ->where('user_id', $user->id)
            ->where('type', JarvisNotificationType::ProactiveSuggestion)
            ->where('occurred_at', '>=', $start)
            ->count();
    }
}
