<?php

namespace App\Services\Watchers;

use App\Models\User;

final class WatcherDigestRequest
{
    /**
     * @return array<string, mixed>|null
     */
    public static function gmailMorningFromInbound(string $inbound, User $user): ?array
    {
        if (! ProactiveCheckIntent::jarvisShouldMonitorMail($inbound)) {
            return null;
        }

        $explicit = WatcherSchedule::parseLocalTime($inbound);
        $localTime = $explicit ?? WatcherSchedule::defaultMorningTime();
        $timezone = WatcherSchedule::timezoneFor($user);

        return [
            'name' => 'Утренняя сводка почты',
            'trigger_type' => 'gmail_message',
            'source_type' => 'gmail',
            'condition_type' => 'new_item',
            'reaction_type' => 'notify',
            'mode' => 'recurring',
            'cooldown_seconds' => 0,
            'max_triggers_per_day' => 3,
            'aggregation_window_seconds' => 0,
            'source' => [
                'query' => 'in:inbox',
                'digest' => true,
                'schedule' => [
                    'kind' => WatcherSchedule::KIND_DAILY_LOCAL,
                    'local_time' => $localTime,
                    'timezone' => $timezone,
                ],
            ],
        ];
    }
}
