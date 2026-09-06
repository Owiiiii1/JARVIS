<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderChannel;
use App\Enums\ReminderDeliveryStatus;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Services\Reminders\ChannelAttempt;
use App\Services\Reminders\ReminderDeliveryState;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReminderMultiChannelDeliveryTest extends TestCase
{
    public function test_no_channels_keeps_due_reminder_valid(): void
    {
        $reminder = $this->reminder();
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');

        ReminderDeliveryState::applyAttempts($reminder, [
            $this->skipped(ReminderChannel::Telegram),
            $this->skipped(ReminderChannel::WebPush),
        ], $now);

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame('no_channel', $reminder->metadata['delivery_state']);
        $this->assertSame(0, $reminder->metadata['attempts']);
    }

    public function test_telegram_only_success_marks_delivered(): void
    {
        $reminder = $this->reminder();
        ReminderDeliveryState::applyAttempts($reminder, [
            $this->sent(ReminderChannel::Telegram),
            $this->skipped(ReminderChannel::WebPush),
        ], CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertSame('telegram', $reminder->metadata['delivery_channel']);
    }

    public function test_push_only_success_marks_delivered(): void
    {
        $reminder = $this->reminder();
        ReminderDeliveryState::applyAttempts($reminder, [
            $this->skipped(ReminderChannel::Telegram),
            $this->sent(ReminderChannel::WebPush),
        ], CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertSame('web_push', $reminder->metadata['delivery_channel']);
    }

    public function test_telegram_fail_push_success_does_not_fail_core(): void
    {
        $reminder = $this->reminder();
        ReminderDeliveryState::applyAttempts($reminder, [
            new ChannelAttempt(ReminderChannel::Telegram, ReminderDeliveryStatus::Failed, 1, true, 'telegram_delivery_failed'),
            $this->sent(ReminderChannel::WebPush),
        ], CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertNotSame(ReminderStatus::Failed, $reminder->status);
        $this->assertSame('partial', $reminder->metadata['delivery_state']);
        $this->assertSame('web_push', $reminder->metadata['delivery_channel']);
    }

    public function test_push_fail_telegram_success_does_not_fail_core(): void
    {
        $reminder = $this->reminder();
        ReminderDeliveryState::applyAttempts($reminder, [
            $this->sent(ReminderChannel::Telegram),
            new ChannelAttempt(ReminderChannel::WebPush, ReminderDeliveryStatus::Failed, 1, true, 'web_push_delivery_failed'),
        ], CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertSame('partial', $reminder->metadata['delivery_state']);
    }

    public function test_both_fail_retries_then_fails_one_shot(): void
    {
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $reminder = $this->reminder();

        ReminderDeliveryState::applyAttempts($reminder, [
            new ChannelAttempt(ReminderChannel::Telegram, ReminderDeliveryStatus::Failed, 1, true, 'telegram_delivery_failed'),
            new ChannelAttempt(ReminderChannel::WebPush, ReminderDeliveryStatus::Failed, 1, true, 'web_push_delivery_failed'),
        ], $now);

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertArrayHasKey('next_retry_at', $reminder->metadata);

        ReminderDeliveryState::applyAttempts($reminder, [
            new ChannelAttempt(ReminderChannel::Telegram, ReminderDeliveryStatus::Failed, 3, false, 'telegram_delivery_failed'),
            new ChannelAttempt(ReminderChannel::WebPush, ReminderDeliveryStatus::Failed, 3, false, 'web_push_delivery_failed'),
        ], $now);

        $this->assertSame(ReminderStatus::Failed, $reminder->status);
    }

    public function test_both_success_marks_both_channel(): void
    {
        $reminder = $this->reminder();
        ReminderDeliveryState::applyAttempts($reminder, [
            $this->sent(ReminderChannel::Telegram),
            $this->sent(ReminderChannel::WebPush),
        ], CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertSame('both', $reminder->metadata['delivery_channel']);
    }

    public function test_recurring_success_advances_and_keeps_history(): void
    {
        $reminder = $this->reminder([
            'recurrence_rule' => 'daily',
            'run_at' => CarbonImmutable::parse('2026-09-06 07:00:00', 'UTC'),
            'status' => ReminderStatus::Processing,
        ]);

        ReminderDeliveryState::applyAttempts($reminder, [
            $this->sent(ReminderChannel::Telegram),
            $this->skipped(ReminderChannel::WebPush),
        ], CarbonImmutable::parse('2026-09-06 07:01:00', 'UTC'));

        $this->assertSame(ReminderStatus::Scheduled, $reminder->status);
        $this->assertSame('2026-09-07', $reminder->run_at->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame('delivered', $reminder->metadata['last_occurrence']['status']);
        $this->assertNotEmpty($reminder->metadata['occurrence_history']);
    }

    private function skipped(ReminderChannel $channel): ChannelAttempt
    {
        return new ChannelAttempt($channel, ReminderDeliveryStatus::Skipped, 0, false);
    }

    private function sent(ReminderChannel $channel): ChannelAttempt
    {
        return new ChannelAttempt($channel, ReminderDeliveryStatus::Sent, 1, false);
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
