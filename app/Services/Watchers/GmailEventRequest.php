<?php

namespace App\Services\Watchers;

use App\Enums\WatcherMode;
use App\Models\User;

final class GmailEventRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function fromInbound(string $inbound, User $user, array $input = []): ?array
    {
        unset($user);
        if (! ProactiveCheckIntent::isGmailEventMonitoring($inbound) && ! self::inputLooksLikeGmailEvent($input)) {
            return null;
        }

        if (ProactiveCheckIntent::userSelfReminder($inbound) || ProactiveCheckIntent::isPeriodicDigest($inbound)) {
            return null;
        }

        $source = is_array($input['source'] ?? $input['source_config'] ?? null)
            ? ($input['source'] ?? $input['source_config'])
            : [];
        $extracted = GmailWatcherQuery::extractFromText($inbound);
        $source = GmailWatcherQuery::merge($source, $extracted);

        $oneShot = ($input['mode'] ?? '') === 'one_shot'
            || ($input['one_shot'] ?? false) === true
            || ProactiveCheckIntent::wantsOneShotMailAlert($inbound);

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = GmailWatcherQuery::hasFilter($source)
                ? GmailWatcherQuery::displayName($source)
                : 'Письма от выбранных отправителей';
        }

        return [
            'name' => $name,
            'trigger_type' => 'gmail_message',
            'source_type' => 'gmail',
            'condition_type' => 'new_item',
            'reaction_type' => 'notify',
            'mode' => $oneShot ? WatcherMode::OneShot->value : WatcherMode::Recurring->value,
            'cooldown_seconds' => 0,
            'max_triggers_per_day' => 24,
            'aggregation_window_seconds' => 0,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function inputLooksLikeGmailEvent(array $input): bool
    {
        $trigger = mb_strtolower(trim((string) ($input['trigger_type'] ?? '')));
        $source = is_array($input['source'] ?? null) ? $input['source'] : [];

        if (WatcherSchedule::isDigest($source)) {
            return false;
        }

        return $trigger === 'gmail_message' || GmailWatcherQuery::hasFilter($source);
    }
}
