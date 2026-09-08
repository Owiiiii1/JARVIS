<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\ScheduledReportCollector;
use PHPUnit\Framework\TestCase;

class ScheduledReportCalendarScopeTest extends TestCase
{
    public function test_all_relevant_includes_primary_and_family_calendars(): void
    {
        $calendars = [
            ['id' => 'primary', 'summary' => 'Owner', 'primary' => true, 'selected' => true],
            ['id' => 'family@group.calendar.google.com', 'summary' => 'Семья', 'primary' => false, 'selected' => true],
            ['id' => 'holidays', 'summary' => 'Holidays in Italy', 'primary' => false, 'selected' => false],
        ];

        $selected = ScheduledReportCollector::selectCalendars($calendars, [
            'type' => 'google_calendar',
            'calendar_scope' => 'all_relevant',
        ]);

        $ids = array_map(static fn (array $row): string => (string) $row['id'], $selected);

        $this->assertContains('primary', $ids);
        $this->assertContains('family@group.calendar.google.com', $ids);
        $this->assertNotContains('holidays', $ids);
    }
}
