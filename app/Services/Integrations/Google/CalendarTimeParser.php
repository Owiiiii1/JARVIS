<?php

namespace App\Services\Integrations\Google;

use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

final class CalendarTimeParser
{
    public function ownerTimezone(User $user): string
    {
        $timezone = trim((string) ($user->timezone ?: 'UTC'));

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    public function assertValidTimezone(string $timezone): string
    {
        $timezone = trim($timezone);

        if ($timezone === '') {
            throw new IntegrationException('invalid_arguments', 'Timezone is required.');
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            throw new IntegrationException('invalid_arguments', 'Timezone is invalid.');
        }
    }

    public function parseDateTime(string $value, string $timezone): Carbon
    {
        $value = trim($value);

        if ($value === '') {
            throw new IntegrationException('invalid_arguments', 'Datetime is required.');
        }

        try {
            if ($this->hasExplicitOffset($value)) {
                return Carbon::parse($value);
            }

            return Carbon::parse($value, $timezone);
        } catch (Throwable) {
            throw new IntegrationException('invalid_arguments', 'Datetime is invalid.');
        }
    }

    public function parseDate(string $value, string $timezone): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new IntegrationException('invalid_arguments', 'Date is required.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return $this->parseDateTime($value, $timezone)->timezone($timezone)->toDateString();
    }

    /**
     * @return array{dateTime: string, timeZone: string}
     */
    public function googleDateTime(Carbon $instant, string $timezone): array
    {
        return [
            'dateTime' => $instant->copy()->timezone($timezone)->format('Y-m-d\TH:i:s'),
            'timeZone' => $timezone,
        ];
    }

    public function assertOrder(Carbon $start, Carbon $end): void
    {
        if ($start->gte($end)) {
            throw new IntegrationException('invalid_arguments', 'Start must be before end.');
        }
    }

    public function assertDayRange(int $days, int $maxDays): void
    {
        if ($days < 0 || $days > $maxDays) {
            throw new IntegrationException('invalid_arguments', 'Time range exceeds the configured maximum.');
        }
    }

    public function hasExplicitOffset(string $value): bool
    {
        return (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', trim($value));
    }
}
