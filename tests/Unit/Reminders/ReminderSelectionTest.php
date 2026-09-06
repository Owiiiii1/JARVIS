<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Services\Reminders\ReminderSelection;
use Tests\TestCase;

class ReminderSelectionTest extends TestCase
{
    public function test_id_selects_only_the_owned_candidate(): void
    {
        $doctor = $this->reminder(1, 'врач');
        $tea = $this->reminder(2, 'чайник');

        $selected = ReminderSelection::resolve(1, null, [$doctor, $tea]);

        $this->assertTrue($selected['ok']);
        $this->assertSame(1, $selected['reminder']->id);
    }

    public function test_ambiguous_query_does_not_pick_a_reminder(): void
    {
        $first = $this->reminder(1, 'напомни про врача утром');
        $second = $this->reminder(2, 'врач вечером');

        $selected = ReminderSelection::resolve(null, 'врач', [$first, $second]);

        $this->assertFalse($selected['ok']);
        $this->assertSame('ambiguous', $selected['error']);
        $this->assertCount(2, $selected['candidates']);
        $this->assertSame(ReminderStatus::Scheduled, $first->status);
        $this->assertSame(ReminderStatus::Scheduled, $second->status);
    }

    public function test_unique_query_selects_one(): void
    {
        $selected = ReminderSelection::resolve(null, 'чайник', [
            $this->reminder(1, 'врач'),
            $this->reminder(2, 'чайник'),
        ]);

        $this->assertTrue($selected['ok']);
        $this->assertSame(2, $selected['reminder']->id);
    }

    public function test_missing_id_is_not_found_without_mutation(): void
    {
        $reminder = $this->reminder(1, 'врач');
        $selected = ReminderSelection::resolve(99, 'врач', [$reminder]);

        $this->assertFalse($selected['ok']);
        $this->assertSame('not_found', $selected['error']);
        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
    }

    private function reminder(int $id, string $text): Reminder
    {
        $reminder = new Reminder;
        $reminder->forceFill([
            'id' => $id,
            'user_id' => 4,
            'text' => $text,
            'status' => ReminderStatus::Scheduled,
        ]);

        return $reminder;
    }
}
