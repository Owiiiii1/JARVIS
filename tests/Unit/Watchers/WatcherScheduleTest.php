<?php

namespace Tests\Unit\Watchers;

use App\Enums\WatcherTriggerType;
use App\Models\Watcher;
use App\Services\Watchers\WatcherSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WatcherScheduleTest extends TestCase
{
    public function test_default_morning_time_is_eight(): void
    {
        $this->assertSame('08:00', WatcherSchedule::defaultMorningTime());
    }

    public function test_parse_local_time_reads_an_explicit_clock(): void
    {
        $this->assertSame('07:30', WatcherSchedule::parseLocalTime('Каждое утро в 7:30 проверяй почту'));
        $this->assertSame('08:00', WatcherSchedule::parseLocalTime('Каждый день в 8 проверяй Gmail.'));
        $this->assertNull(WatcherSchedule::parseLocalTime('Проверяй каждое утро почту'));
    }

    public function test_next_daily_local_slot_uses_user_timezone(): void
    {
        $afterMorning = CarbonImmutable::parse('2026-09-08 10:00:00', 'Europe/Rome')->utc();
        $next = WatcherSchedule::nextDailyLocal($afterMorning, 'Europe/Rome', '08:00');

        $this->assertSame('2026-09-09 08:00', $next->setTimezone('Europe/Rome')->format('Y-m-d H:i'));

        $beforeMorning = CarbonImmutable::parse('2026-09-08 07:00:00', 'Europe/Rome')->utc();
        $sameDay = WatcherSchedule::nextDailyLocal($beforeMorning, 'Europe/Rome', '08:00');

        $this->assertSame('2026-09-08 08:00', $sameDay->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
    }

    public function test_digest_watchers_schedule_tomorrow_after_a_morning_run(): void
    {
        $watcher = new Watcher([
            'trigger_type' => WatcherTriggerType::GmailMessage,
            'source_config' => [
                'digest' => true,
                'schedule' => ['kind' => WatcherSchedule::KIND_DAILY_LOCAL, 'local_time' => '08:00'],
            ],
        ]);
        $from = CarbonImmutable::parse('2026-09-08 08:05:00', 'Europe/Rome')->utc();
        $next = WatcherSchedule::nextCheckAt($watcher, $from, 'Europe/Rome');

        $this->assertSame('2026-09-09 08:00', $next->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
    }
}
