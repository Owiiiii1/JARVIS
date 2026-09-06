<?php

namespace App\Services\Reminders;

use App\Enums\ReminderRecurrence;
use Carbon\CarbonImmutable;
use DateTimeZone;

final class ReminderRecurrenceCalculator
{
    public static function parse(?string $rule): ?ReminderRecurrence
    {
        $normalized = strtolower(trim((string) $rule));

        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['every ', 'each '], '', $normalized);

        return match ($normalized) {
            'daily', 'day', 'everyday' => ReminderRecurrence::Daily,
            'weekdays', 'weekday', 'workdays', 'будни' => ReminderRecurrence::Weekdays,
            'weekly', 'week' => ReminderRecurrence::Weekly,
            'monthly', 'month' => ReminderRecurrence::Monthly,
            default => null,
        };
    }

    public static function normalize(?string $rule): ?string
    {
        return self::parse($rule)?->value;
    }

    public function nextRunAt(CarbonImmutable $fromUtc, string $timezone, ReminderRecurrence $recurrence): CarbonImmutable
    {
        $local = $fromUtc->utc()->setTimezone($timezone);
        $hour = (int) $local->format('H');
        $minute = (int) $local->format('i');
        $second = (int) $local->format('s');

        $cursor = $this->wallClockOn($local->addDay(), $timezone, $hour, $minute, $second);

        return match ($recurrence) {
            ReminderRecurrence::Daily => $this->ensureAfter($fromUtc, $cursor, $timezone, $hour, $minute, $second, 1),
            ReminderRecurrence::Weekdays => $this->nextWeekday($fromUtc, $timezone, $hour, $minute, $second),
            ReminderRecurrence::Weekly => $this->ensureAfter(
                $fromUtc,
                $this->wallClockOn($local->addWeek(), $timezone, $hour, $minute, $second),
                $timezone,
                $hour,
                $minute,
                $second,
                7,
            ),
            ReminderRecurrence::Monthly => $this->nextMonthly($fromUtc, $local, $timezone, $hour, $minute, $second),
        };
    }

    private function nextWeekday(
        CarbonImmutable $fromUtc,
        string $timezone,
        int $hour,
        int $minute,
        int $second,
    ): CarbonImmutable {
        $local = $fromUtc->utc()->setTimezone($timezone);
        $cursor = $this->wallClockOn($local->addDay(), $timezone, $hour, $minute, $second);

        for ($i = 0; $i < 10; $i++) {
            $isoDow = (int) $cursor->format('N');

            if ($isoDow <= 5 && $cursor->utc()->greaterThan($fromUtc->utc())) {
                return $cursor->utc();
            }

            $cursor = $this->wallClockOn($cursor->addDay(), $timezone, $hour, $minute, $second);
        }

        return $cursor->utc();
    }

    private function nextMonthly(
        CarbonImmutable $fromUtc,
        CarbonImmutable $local,
        string $timezone,
        int $hour,
        int $minute,
        int $second,
    ): CarbonImmutable {
        $nextLocal = $local->addMonthNoOverflow();
        $cursor = $this->wallClockOn($nextLocal, $timezone, $hour, $minute, $second);

        return $this->ensureAfter($fromUtc, $cursor, $timezone, $hour, $minute, $second, 0, true);
    }

    private function ensureAfter(
        CarbonImmutable $fromUtc,
        CarbonImmutable $candidate,
        string $timezone,
        int $hour,
        int $minute,
        int $second,
        int $addDays,
        bool $monthly = false,
    ): CarbonImmutable {
        $cursor = $candidate;

        for ($i = 0; $i < 5 && ! $cursor->utc()->greaterThan($fromUtc->utc()); $i++) {
            $cursor = $monthly
                ? $this->wallClockOn($cursor->addMonthNoOverflow(), $timezone, $hour, $minute, $second)
                : $this->wallClockOn($cursor->addDays(max(1, $addDays)), $timezone, $hour, $minute, $second);
        }

        return $cursor->utc();
    }

    private function wallClockOn(
        CarbonImmutable $day,
        string $timezone,
        int $hour,
        int $minute,
        int $second,
    ): CarbonImmutable {
        return CarbonImmutable::create(
            (int) $day->format('Y'),
            (int) $day->format('m'),
            (int) $day->format('d'),
            $hour,
            $minute,
            $second,
            new DateTimeZone($timezone),
        );
    }
}
