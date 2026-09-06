<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\ReminderStatus;
use App\Enums\WatcherTriggerType;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class ReminderWatcherSource implements WatcherSourceAdapter
{
    public function supports(Watcher $watcher): bool
    {
        return $watcher->trigger_type === WatcherTriggerType::ReminderState;
    }

    public function check(User $user, Watcher $watcher): array
    {
        $reminderId = (int) ($watcher->reminder_id ?? ($watcher->source_config['reminder_id'] ?? 0));
        $reminder = Reminder::query()->where('user_id', $user->id)->whereKey($reminderId)->first();

        if ($reminder === null) {
            return [];
        }

        $status = $reminder->status instanceof ReminderStatus ? $reminder->status->value : (string) $reminder->status;

        return [
            new WatcherObservation(
                sourceType: 'reminder',
                sourceId: (string) $reminder->id,
                eventType: 'reminder_state',
                fingerprint: WatcherSupport::fingerprint('reminder', (string) $watcher->id, (string) $reminder->id, $status),
                occurredAt: CarbonImmutable::now('UTC'),
                title: (string) $reminder->text,
                metadata: ['status' => $status],
            ),
        ];
    }
}
