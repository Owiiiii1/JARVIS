<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\ScheduledReportSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduledReportScheduleTest extends TestCase
{
    public function test_created_before_todays_slot_runs_today(): void
    {
        $created = CarbonImmutable::parse('2026-09-08 19:39:00', 'UTC');
        $next = ScheduledReportSchedule::firstRunAt($created, 'Europe/Rome', '22:00');

        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-09-08 20:00:00', 'UTC')));
    }

    public function test_created_after_todays_slot_runs_next_day(): void
    {
        $created = CarbonImmutable::parse('2026-09-08 20:01:00', 'UTC');
        $next = ScheduledReportSchedule::firstRunAt($created, 'Europe/Rome', '22:00');

        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-09-09 20:00:00', 'UTC')));
    }

    public function test_parse_local_time_accepts_russian_clocks(): void
    {
        $this->assertSame('22:00', ScheduledReportSchedule::parseLocalTime('каждый вечер в 22:00 присылай отчет'));
        $this->assertSame('08:30', ScheduledReportSchedule::parseLocalTime('каждое утро в 8:30 планы'));
        $this->assertSame('09:00', ScheduledReportSchedule::parseLocalTime('каждое утро в 9:00 письма'));
    }

    public function test_slot_key_is_local_date_and_time(): void
    {
        $at = CarbonImmutable::parse('2026-09-08 20:03:00', 'UTC');

        $this->assertSame('2026-09-08|22:00', ScheduledReportSchedule::slotKey($at, 'Europe/Rome', '22:00'));
    }
}
