<?php

namespace App\Support;

final class Timezones
{
    /**
     * @return list<string>
     */
    public static function common(): array
    {
        return [
            'UTC',
            'Europe/Rome',
            'Europe/Kyiv',
            'Europe/London',
            'Europe/Berlin',
            'Europe/Paris',
            'Europe/Madrid',
            'Europe/Warsaw',
            'Europe/Amsterdam',
            'America/New_York',
            'America/Chicago',
            'America/Los_Angeles',
            'Asia/Tbilisi',
            'Asia/Dubai',
            'Asia/Almaty',
            'Asia/Tokyo',
        ];
    }

    /**
     * @return list<string>
     */
    public static function options(?string $current = null): array
    {
        $values = self::common();
        $current = is_string($current) ? trim($current) : '';

        if ($current !== '' && ! in_array($current, $values, true) && self::isValid($current)) {
            $values[] = $current;
        }

        return $values;
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, timezone_identifiers_list(), true);
    }
}
