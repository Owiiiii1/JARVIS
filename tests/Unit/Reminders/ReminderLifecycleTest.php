<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Services\Reminders\ReminderLifecycle;
use App\Services\Reminders\ReminderRecurrenceCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReminderLifecycleTest extends TestCase
{
    public function test_edit_resets_delivery_and_keeps_the_same_reminder(): void
    {
        $reminder = $this->reminder([
            'metadata' => ['attempts' => 2, 'delivery_state' => 'error', 'telegram_attempts' => 2],
            'last_error' => 'telegram_delivery_failed',
        ]);

        ReminderLifecycle::applySchedule(
            $reminder,
            CarbonImmutable::parse('2026-09-07 09:00:00', 'UTC'),
            'Europe/Rome',
            'daily',
        );

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame('daily', $reminder->recurrence_rule);
        $this->assertSame(0, $reminder->metadata['attempts']);
        $this->assertArrayNotHasKey('next_retry_at', $reminder->metadata);
        $this->assertNull($reminder->last_error);
        $this->assertSame(10, $reminder->id);
    }

    public function test_snooze_ten_minutes_reschedules_without_duplicating(): void
    {
        $this->travelTo('2026-09-06 12:00:00');
        $reminder = $this->reminder();
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $runAt = ReminderLifecycle::resolveSnoozeAt($reminder, '10m', $now);
        ReminderLifecycle::snoozeTo($reminder, $runAt, 'Europe/Rome');

        $this->assertSame('2026-09-06 12:10:00', $reminder->run_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame(10, $reminder->id);
    }

    public function test_done_marks_one_shot_completed_not_delivered(): void
    {
        $reminder = $this->reminder();
        ReminderLifecycle::markCompleted($reminder, CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Completed, $reminder->status);
        $this->assertNotSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertNotNull($reminder->completed_at);
    }

    public function test_cancel_is_distinct_from_done(): void
    {
        $reminder = $this->reminder();
        ReminderLifecycle::markCancelled($reminder, CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Cancelled, $reminder->status);
        $this->assertNull($reminder->completed_at);
        $this->assertNotNull($reminder->cancelled_at);
    }

    public function test_recurring_done_keeps_history_and_advances_next_run(): void
    {
        $reminder = $this->reminder([
            'recurrence_rule' => 'daily',
            'run_at' => CarbonImmutable::parse('2026-09-06 07:00:00', 'UTC'),
        ]);
        $now = CarbonImmutable::parse('2026-09-06 07:05:00', 'UTC');
        $occurrenceAt = $reminder->run_at;
        ReminderLifecycle::recordOccurrence($reminder, ReminderStatus::Completed, $occurrenceAt, $now);
        $next = ReminderLifecycle::advanceRecurring($reminder, $occurrenceAt, $now, new ReminderRecurrenceCalculator);

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame('09:00', $next->setTimezone('Europe/Rome')->format('H:i'));
        $this->assertSame('2026-09-07', $next->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('completed', $reminder->metadata['last_occurrence']['status']);
        $this->assertNotEmpty($reminder->metadata['occurrence_history']);
    }

    public function test_completed_and_cancelled_are_not_editable(): void
    {
        $completed = $this->reminder(['status' => ReminderStatus::Completed]);
        $cancelled = $this->reminder(['status' => ReminderStatus::Cancelled]);

        $this->assertFalse(ReminderLifecycle::isEditable($completed));
        $this->assertFalse(ReminderLifecycle::isEditable($cancelled));
        $this->assertFalse(ReminderLifecycle::isSnoozable($completed));
        $this->assertTrue(ReminderLifecycle::isCompletable($this->reminder(['status' => ReminderStatus::Delivered])));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reminder(array $attributes = []): Reminder
    {
        $reminder = new Reminder;
        $reminder->forceFill(array_merge([
            'id' => 10,
            'user_id' => 1,
            'text' => 'врач',
            'run_at' => CarbonImmutable::parse('2026-09-06 15:00:00', 'UTC'),
            'timezone' => 'Europe/Rome',
            'status' => ReminderStatus::Scheduled,
            'metadata' => ['attempts' => 0],
        ], $attributes));

        return $reminder;
    }
}
