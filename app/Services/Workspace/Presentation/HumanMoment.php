<?php

namespace App\Services\Workspace\Presentation;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns an instant into the short Russian wording the Workspace uses everywhere:
 * «Сегодня, 18:00», «Завтра, 11:00», «6 сент., 18:30».
 */
final class HumanMoment
{
    private const MONTHS = [
        1 => 'янв.', 2 => 'февр.', 3 => 'мар.', 4 => 'апр.', 5 => 'мая', 6 => 'июн.',
        7 => 'июл.', 8 => 'авг.', 9 => 'сент.', 10 => 'окт.', 11 => 'нояб.', 12 => 'дек.',
    ];

    public static function label(mixed $moment, string $timezone, ?CarbonImmutable $now = null): ?string
    {
        $local = self::local($moment, $timezone);

        if ($local === null) {
            return null;
        }

        return self::dayPart($local, $timezone, $now).', '.$local->format('H:i');
    }

    public static function dayLabel(mixed $moment, string $timezone, ?CarbonImmutable $now = null): ?string
    {
        $local = self::local($moment, $timezone);

        if ($local === null) {
            return null;
        }

        return self::dayPart($local, $timezone, $now);
    }

    /**
     * Bounded duration wording for watcher conditions and snooze copy.
     */
    public static function hours(int $hours): string
    {
        $hours = max(1, $hours);

        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days === 1 ? 'сутки' : $days.' '.self::days($days);
        }

        return $hours.' '.self::plural($hours, 'час', 'часа', 'часов');
    }

    public static function days(int $days): string
    {
        return self::plural($days, 'день', 'дня', 'дней');
    }

    public static function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        if ($mod10 === 1) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }

    private static function dayPart(CarbonImmutable $local, string $timezone, ?CarbonImmutable $now): string
    {
        $reference = ($now ?? CarbonImmutable::now('UTC'));

        try {
            $reference = $reference->setTimezone($timezone);
        } catch (Throwable) {
            $reference = $reference->utc();
        }

        $days = (int) round($local->startOfDay()->diffInDays($reference->startOfDay(), false));

        return match (true) {
            $days === 0 => 'Сегодня',
            $days === -1 => 'Завтра',
            $days === 1 => 'Вчера',
            default => self::calendarDay($local, $reference),
        };
    }

    private static function calendarDay(CarbonImmutable $local, CarbonImmutable $reference): string
    {
        $day = ((int) $local->format('j')).' '.(self::MONTHS[(int) $local->format('n')] ?? '');

        return $local->format('Y') === $reference->format('Y')
            ? trim($day)
            : trim($day).' '.$local->format('Y');
    }

    private static function local(mixed $moment, string $timezone): ?CarbonImmutable
    {
        if ($moment === null || $moment === '') {
            return null;
        }

        try {
            $instant = $moment instanceof CarbonImmutable
                ? $moment
                : CarbonImmutable::parse((string) $moment);

            return $instant->setTimezone($timezone !== '' ? $timezone : 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
