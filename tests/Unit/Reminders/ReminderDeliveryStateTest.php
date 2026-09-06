<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Services\Reminders\ReminderDeliveryState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReminderDeliveryStateTest extends TestCase
{
    public function test_no_channel_returns_to_scheduled_without_incrementing_attempts(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $reminder = $this->reminder(['metadata' => ['attempts' => 0]]);

        ReminderDeliveryState::deferNoChannel($reminder, $now);

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertNotSame(ReminderStatus::Failed, $reminder->status);
        $this->assertNotSame(ReminderStatus::Cancelled, $reminder->status);
        $this->assertNotSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertSame('no_channel', $reminder->metadata['delivery_state']);
        $this->assertNull($reminder->metadata['delivery_channel']);
        $this->assertSame(0, $reminder->metadata['attempts']);
        $this->assertNull($reminder->last_error);
        $this->assertSame(
            $now->addMinutes(ReminderDeliveryState::NO_CHANNEL_RECHECK_MINUTES)->toDateTimeString(),
            $reminder->metadata['next_retry_at'],
        );
        $this->assertSame(30, ReminderDeliveryState::NO_CHANNEL_RECHECK_MINUTES);
    }

    public function test_successful_telegram_delivery_marks_delivered(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $reminder = $this->reminder();

        ReminderDeliveryState::markDelivered($reminder, $now);

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertTrue($reminder->delivered_at?->eq($now));
        $this->assertSame('delivered', $reminder->metadata['delivery_state']);
        $this->assertSame('telegram', $reminder->metadata['delivery_channel']);
        $this->assertArrayNotHasKey('next_retry_at', $reminder->metadata);
        $this->assertNull($reminder->last_error);
    }

    public function test_telegram_send_failure_schedules_bounded_retry(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $reminder = $this->reminder(['metadata' => ['attempts' => 0]]);

        ReminderDeliveryState::retryOrFail($reminder, 'telegram_delivery_failed', $now);

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame(1, $reminder->metadata['attempts']);
        $this->assertSame('error', $reminder->metadata['delivery_state']);
        $this->assertSame('telegram', $reminder->metadata['delivery_channel']);
        $this->assertSame('telegram_delivery_failed', $reminder->last_error);
        $this->assertSame($now->addMinutes(1)->toDateTimeString(), $reminder->metadata['next_retry_at']);
    }

    public function test_telegram_send_failure_fails_after_three_attempts(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $reminder = $this->reminder(['metadata' => ['attempts' => 2]]);

        ReminderDeliveryState::retryOrFail($reminder, 'telegram_delivery_failed', $now);

        $this->assertSame(ReminderStatus::Failed, $reminder->status);
        $this->assertSame(3, $reminder->metadata['attempts']);
        $this->assertSame('telegram_delivery_failed', $reminder->last_error);
        $this->assertArrayNotHasKey('next_retry_at', $reminder->metadata);
    }

    public function test_no_channel_does_not_consume_delivery_attempts(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $reminder = $this->reminder(['metadata' => ['attempts' => 2]]);

        ReminderDeliveryState::deferNoChannel($reminder, $now);

        $this->assertSame(2, $reminder->metadata['attempts']);
        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame('no_channel', $reminder->metadata['delivery_state']);
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
            'text' => 'проверить Jarvis',
            'run_at' => CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'),
            'timezone' => 'Europe/Rome',
            'status' => ReminderStatus::Processing,
            'metadata' => ['attempts' => 0],
        ], $attributes));

        return $reminder;
    }
}
