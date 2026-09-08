<?php

namespace App\Services\Reports;

use App\Models\ScheduledReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class ScheduledReportSchedule
{
    public static function timezoneFor(User|ScheduledReport|null $owner, string $fallback = 'UTC'): string
    {
        if ($owner instanceof ScheduledReport) {
            $explicit = trim((string) ($owner->timezone ?: ''));
            if ($explicit !== '') {
                return self::validTimezone($explicit);
            }
            $owner = $owner->user;
        }

        $timezone = trim((string) ($owner?->timezone ?: $fallback));

        return self::validTimezone($timezone !== '' ? $timezone : $fallback);
    }

    public static function normalizeTime(mixed $value): ?string
    {
        $raw = is_string($value) ? trim($value) : '';
        $raw = str_replace('.', ':', $raw);

        if (preg_match('/^(\d{1,2})[,:](\d{2})$/', $raw, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public static function parseLocalTime(string $text): ?string
    {
        $normalized = mb_strtolower($text);

        if (preg_match('/(?:в|at)\s+(\d{1,2})[:.,](\d{2})/u', $normalized, $matches) === 1) {
            return self::normalizeTime($matches[1].':'.$matches[2]);
        }

        if (preg_match('/(?:в|at)\s+(\d{1,2})\b/u', $normalized, $matches) === 1) {
            return self::normalizeTime($matches[1].':00');
        }

        return null;
    }

    public static function nextDailyLocal(CarbonImmutable $fromUtc, string $timezone, string $localTime, bool $allowToday = true): CarbonImmutable
    {
        $tz = self::dateTimezone($timezone);
        $local = $fromUtc->utc()->setTimezone($tz);
        $parts = explode(':', self::normalizeTime($localTime) ?? '08:00');
        $slot = $local->setTime((int) ($parts[0] ?? 8), (int) ($parts[1] ?? 0), 0);

        if ($allowToday && $slot->greaterThan($local)) {
            return $slot->utc();
        }

        return $slot->addDay()->utc();
    }

    public static function firstRunAt(CarbonImmutable $createdAtUtc, string $timezone, string $localTime): CarbonImmutable
    {
        return self::nextDailyLocal($createdAtUtc, $timezone, $localTime, allowToday: true);
    }

    public static function slotKey(CarbonImmutable $atUtc, string $timezone, string $localTime): string
    {
        $local = $atUtc->utc()->setTimezone(self::dateTimezone($timezone));

        return $local->toDateString().'|'.(self::normalizeTime($localTime) ?? '08:00');
    }

    public static function isDue(ScheduledReport $report, CarbonImmutable $nowUtc): bool
    {
        if (! $report->isActive()) {
            return false;
        }

        $next = $report->next_run_at;

        return $next instanceof CarbonImmutable && $next->utc()->lessThanOrEqualTo($nowUtc->utc());
    }

    private static function validTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Exception) {
            return 'UTC';
        }
    }

    private static function dateTimezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
