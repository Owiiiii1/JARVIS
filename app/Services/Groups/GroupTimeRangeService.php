<?php

namespace App\Services\Groups;

use App\Models\TelegramGroup;
use App\Services\Groups\Exceptions\GroupAnalysisException;
use App\Services\Groups\Exceptions\TelegramGroupException;
use Carbon\CarbonImmutable;
use DateTimeZone;

final class GroupTimeRangeService
{
    public function __construct(
        private readonly TelegramGroupDiscoveryService $discovery,
    ) {}

    public function timezone(TelegramGroup $group): DateTimeZone
    {
        try {
            $owner = $this->discovery->owner();
        } catch (TelegramGroupException) {
            $owner = null;
        }

        return new DateTimeZone($group->effectiveTimezone($owner?->timezone));
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function today(TelegramGroup $group, ?CarbonImmutable $now = null): array
    {
        $tz = $this->timezone($group);
        $local = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($tz)->startOfDay();

        return $this->localDaySpan($local);
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function yesterday(TelegramGroup $group, ?CarbonImmutable $now = null): array
    {
        $tz = $this->timezone($group);
        $local = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($tz)->startOfDay()->subDay();

        return $this->localDaySpan($local);
    }

    /**
     * Inclusive local calendar days ending today.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function lastDays(TelegramGroup $group, int $days, ?CarbonImmutable $now = null): array
    {
        $days = max(1, $days);
        $tz = $this->timezone($group);
        $today = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($tz)->startOfDay();
        $from = $today->subDays($days - 1);
        $to = $today->addDay();

        return [
            'from' => $from->setTimezone('UTC'),
            'to' => $to->setTimezone('UTC'),
        ];
    }

    /**
     * Inclusive local dates Y-m-d.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public function customLocalDates(TelegramGroup $group, string $fromDate, string $toDate): array
    {
        $tz = $this->timezone($group);
        $from = $this->parseLocalDate($fromDate, $tz);
        $to = $this->parseLocalDate($toDate, $tz);

        if ($to->lt($from)) {
            throw new GroupAnalysisException('The custom range end must be on or after the start.');
        }

        $maxDays = (int) config('group_analysis.max_range_days');
        $spanDays = $from->diffInDays($to) + 1;

        if ($spanDays > $maxDays) {
            throw new GroupAnalysisException('Custom range cannot exceed '.$maxDays.' days.');
        }

        return [
            'from' => $from->setTimezone('UTC'),
            'to' => $to->addDay()->setTimezone('UTC'),
        ];
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    private function localDaySpan(CarbonImmutable $localStart): array
    {
        return [
            'from' => $localStart->setTimezone('UTC'),
            'to' => $localStart->addDay()->setTimezone('UTC'),
        ];
    }

    private function parseLocalDate(string $date, DateTimeZone $tz): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new GroupAnalysisException('Dates must use Y-m-d.');
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        } catch (\Throwable) {
            throw new GroupAnalysisException('Invalid local date.');
        }
    }
}
