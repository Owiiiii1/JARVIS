<?php

namespace App\Services\Synthesis;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

final class SynthesisClock
{
    public function timezone(User $user): string
    {
        $timezone = (string) ($user->timezone ?: 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return 'UTC';
        }

        return $timezone;
    }

    public function local(CarbonImmutable $now, string $timezone): CarbonImmutable
    {
        try {
            return $now->utc()->setTimezone($timezone);
        } catch (Exception) {
            return $now->utc();
        }
    }

    public function parseWindowDays(mixed $window, int $default = 7): int
    {
        if (is_int($window) || is_float($window)) {
            return max(1, min(90, (int) $window));
        }

        $raw = is_string($window) ? mb_strtolower(trim($window)) : '';

        if ($raw === '') {
            return max(1, $default);
        }

        if (preg_match('/^(\d+)\s*h/', $raw, $match) === 1) {
            return max(1, min(90, (int) ceil(((int) $match[1]) / 24)));
        }

        if (preg_match('/^(\d+)\s*w/', $raw, $match) === 1) {
            return max(1, min(90, ((int) $match[1]) * 7));
        }

        if (preg_match('/^(\d+)/', $raw, $match) === 1) {
            return max(1, min(90, (int) $match[1]));
        }

        return max(1, $default);
    }

    public function weekStart(CarbonImmutable $localNow): CarbonImmutable
    {
        $weekday = (int) config('productivity.briefs.weekly_weekday', 7);
        $carbonDay = match ($weekday) {
            7 => CarbonImmutable::SUNDAY,
            1 => CarbonImmutable::MONDAY,
            2 => CarbonImmutable::TUESDAY,
            3 => CarbonImmutable::WEDNESDAY,
            4 => CarbonImmutable::THURSDAY,
            5 => CarbonImmutable::FRIDAY,
            6 => CarbonImmutable::SATURDAY,
            default => CarbonImmutable::SUNDAY,
        };

        return $localNow->startOfWeek($carbonDay);
    }
}
