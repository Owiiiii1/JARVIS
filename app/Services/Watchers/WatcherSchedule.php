<?php

namespace App\Services\Watchers;

use App\Models\User;
use App\Models\Watcher;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class WatcherSchedule
{
    public const KIND_DAILY_LOCAL = 'daily_local';

    /**
     * @param  array<string, mixed>  $source
     */
    public static function isDigest(Watcher|array $source): bool
    {
        $config = $source instanceof Watcher
            ? (is_array($source->source_config) ? $source->source_config : [])
            : $source;

        if (($config['digest'] ?? false) === true || ($config['digest'] ?? '') === 'true') {
            return true;
        }

        return self::kind($config) === self::KIND_DAILY_LOCAL;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function kind(array $source): string
    {
        $schedule = is_array($source['schedule'] ?? null) ? $source['schedule'] : [];
        $kind = trim((string) ($schedule['kind'] ?? $schedule['type'] ?? ''));

        return $kind !== '' ? $kind : 'interval';
    }

    public static function defaultMorningTime(): string
    {
        $configured = trim((string) config(
            'watchers.defaults.morning_local_time',
            config('productivity.briefs.daily_local_time', '08:00'),
        ));

        return self::normalizeTime($configured) ?? '08:00';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function localTime(array $source): string
    {
        $schedule = is_array($source['schedule'] ?? null) ? $source['schedule'] : [];
        $raw = trim((string) ($schedule['local_time'] ?? $schedule['time'] ?? ''));

        return self::normalizeTime($raw) ?? self::defaultMorningTime();
    }

    public static function usesDailyLocal(Watcher $watcher): bool
    {
        $source = is_array($watcher->source_config) ? $watcher->source_config : [];

        return self::kind($source) === self::KIND_DAILY_LOCAL;
    }

    public static function nextCheckAt(Watcher $watcher, CarbonImmutable $fromUtc, string $timezone): CarbonImmutable
    {
        if (! self::usesDailyLocal($watcher)) {
            return $fromUtc->addSeconds(WatcherSourceRegistry::cadenceSeconds($watcher->trigger_type));
        }

        $source = is_array($watcher->source_config) ? $watcher->source_config : [];

        return self::nextDailyLocal($fromUtc, $timezone, self::localTime($source));
    }

    public static function nextDailyLocal(CarbonImmutable $fromUtc, string $timezone, string $localTime): CarbonImmutable
    {
        $tz = self::timezone($timezone);
        $local = $fromUtc->setTimezone($tz);
        $parts = explode(':', self::normalizeTime($localTime) ?? '08:00');
        $hour = (int) ($parts[0] ?? 8);
        $minute = (int) ($parts[1] ?? 0);
        $slot = $local->setTime($hour, $minute, 0);

        if ($slot->greaterThan($local)) {
            return $slot->utc();
        }

        return $slot->addDay()->utc();
    }

    public static function localDateKey(CarbonImmutable $atUtc, string $timezone): string
    {
        return $atUtc->setTimezone(self::timezone($timezone))->format('Y-m-d');
    }

    public static function timezoneFor(User|Watcher|null $owner, string $fallback = 'UTC'): string
    {
        if ($owner instanceof Watcher) {
            $owner = $owner->user;
        }

        $timezone = trim((string) ($owner?->timezone ?: $fallback));

        return $timezone !== '' ? $timezone : 'UTC';
    }

    public static function parseLocalTime(string $text): ?string
    {
        if (preg_match('/(?:в|at)\s+(\d{1,2})[:.](\d{2})/u', mb_strtolower($text), $matches) === 1) {
            return self::normalizeTime($matches[1].':'.$matches[2]);
        }

        if (preg_match('/(?:в|at)\s+(\d{1,2})\b/u', mb_strtolower($text), $matches) === 1) {
            return self::normalizeTime($matches[1].':00');
        }

        return null;
    }

    public static function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private static function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
