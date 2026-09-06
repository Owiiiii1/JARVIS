<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderRecurrence;
use App\Services\Reminders\ReminderRecurrenceCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReminderRecurrenceCalculatorTest extends TestCase
{
    public function test_daily_keeps_europe_rome_wall_clock_across_dst_spring(): void
    {
        $from = CarbonImmutable::parse('2026-03-28 08:00:00', 'UTC');
        $next = (new ReminderRecurrenceCalculator)->nextRunAt($from, 'Europe/Rome', ReminderRecurrence::Daily);

        $this->assertSame('09:00', $from->setTimezone('Europe/Rome')->format('H:i'));
        $this->assertSame('2026-03-29', $next->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('09:00', $next->setTimezone('Europe/Rome')->format('H:i'));
        $this->assertSame('2026-03-29 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_daily_keeps_europe_rome_wall_clock_across_dst_fall(): void
    {
        $from = CarbonImmutable::parse('2026-10-24 07:00:00', 'UTC');
        $next = (new ReminderRecurrenceCalculator)->nextRunAt($from, 'Europe/Rome', ReminderRecurrence::Daily);

        $this->assertSame('09:00', $from->setTimezone('Europe/Rome')->format('H:i'));
        $this->assertSame('2026-10-25', $next->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('09:00', $next->setTimezone('Europe/Rome')->format('H:i'));
        $this->assertSame('2026-10-25 08:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_weekdays_skips_weekend(): void
    {
        $friday = CarbonImmutable::parse('2026-09-04 07:00:00', 'UTC');
        $next = (new ReminderRecurrenceCalculator)->nextRunAt($friday, 'Europe/Rome', ReminderRecurrence::Weekdays);

        $this->assertSame('2026-09-07', $next->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('09:00', $next->setTimezone('Europe/Rome')->format('H:i'));
    }

    public function test_weekly_advances_seven_local_days(): void
    {
        $from = CarbonImmutable::parse('2026-09-07 07:00:00', 'UTC');
        $next = (new ReminderRecurrenceCalculator)->nextRunAt($from, 'Europe/Rome', ReminderRecurrence::Weekly);

        $this->assertSame('2026-09-14', $next->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('09:00', $next->setTimezone('Europe/Rome')->format('H:i'));
    }

    public function test_monthly_uses_same_local_day_when_possible(): void
    {
        $from = CarbonImmutable::parse('2026-01-15 08:00:00', 'UTC');
        $next = (new ReminderRecurrenceCalculator)->nextRunAt($from, 'Europe/Rome', ReminderRecurrence::Monthly);

        $this->assertSame('2026-02-15', $next->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('09:00', $next->setTimezone('Europe/Rome')->format('H:i'));
    }

    public function test_parse_accepts_supported_aliases(): void
    {
        $this->assertSame(ReminderRecurrence::Daily, ReminderRecurrenceCalculator::parse('daily'));
        $this->assertSame(ReminderRecurrence::Weekdays, ReminderRecurrenceCalculator::parse('weekdays'));
        $this->assertSame(ReminderRecurrence::Weekly, ReminderRecurrenceCalculator::parse('weekly'));
        $this->assertSame(ReminderRecurrence::Monthly, ReminderRecurrenceCalculator::parse('monthly'));
        $this->assertNull(ReminderRecurrenceCalculator::parse('FREQ=DAILY'));
    }
}
