<?php

namespace App\Services\Watchers;

use App\Enums\WatcherOccurrenceStatus;
use App\Enums\WatcherStatus;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use Carbon\CarbonImmutable;

final class WatcherAntiSpam
{
    public function shouldSuppress(User $user, Watcher $watcher, string $fingerprint): ?string
    {
        if ($watcher->status !== WatcherStatus::Active) {
            return 'inactive';
        }

        $existing = WatcherOccurrence::query()
            ->where('watcher_id', $watcher->id)
            ->where('trigger_fingerprint', $fingerprint)
            ->exists();

        if ($existing) {
            return 'duplicate_fingerprint';
        }

        $cooldown = max(0, (int) $watcher->cooldown_seconds);
        if ($cooldown > 0 && $watcher->last_triggered_at !== null) {
            $until = $watcher->last_triggered_at->addSeconds($cooldown);
            if ($until->greaterThan(CarbonImmutable::now('UTC'))) {
                return 'cooldown';
            }
        }

        $dayStart = CarbonImmutable::now('UTC')->startOfDay();
        $perWatcher = max(1, (int) $watcher->max_triggers_per_day);
        $today = WatcherOccurrence::query()
            ->where('watcher_id', $watcher->id)
            ->where('detected_at', '>=', $dayStart)
            ->whereIn('status', [WatcherOccurrenceStatus::Matched->value, WatcherOccurrenceStatus::Executed->value, WatcherOccurrenceStatus::Aggregated->value])
            ->count();

        if ($today >= $perWatcher) {
            return 'watcher_daily_cap';
        }

        $global = max(1, (int) config('watchers.limits.max_notifications_per_day', 12));
        $userToday = WatcherOccurrence::query()
            ->where('user_id', $user->id)
            ->where('detected_at', '>=', $dayStart)
            ->whereIn('status', [WatcherOccurrenceStatus::Matched->value, WatcherOccurrenceStatus::Executed->value, WatcherOccurrenceStatus::Aggregated->value])
            ->count();

        if ($userToday >= $global) {
            return 'user_daily_cap';
        }

        return null;
    }
}
