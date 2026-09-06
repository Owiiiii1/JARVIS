<?php

namespace App\Services\Reminders;

use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use Carbon\CarbonImmutable;

final class ReminderDeliveryState
{
    public const NO_CHANNEL_RECHECK_MINUTES = 30;

    public const MAX_ATTEMPTS = 3;

    public const STATE_NO_CHANNEL = 'no_channel';

    public const STATE_ERROR = 'error';

    public const STATE_DELIVERED = 'delivered';

    public const STATE_PARTIAL = 'partial';

    /**
     * @param  list<ChannelAttempt>  $attempts
     */
    public static function applyAttempts(
        Reminder $reminder,
        array $attempts,
        CarbonImmutable $now,
        ReminderRecurrenceCalculator $calculator = new ReminderRecurrenceCalculator,
    ): void {
        $available = array_values(array_filter($attempts, static fn (ChannelAttempt $attempt): bool => $attempt->available()));

        if ($available === []) {
            self::deferNoChannel($reminder, $now);

            return;
        }

        $succeeded = array_values(array_filter($available, static fn (ChannelAttempt $attempt): bool => $attempt->succeeded()));
        $channels = array_map(static fn (ChannelAttempt $attempt): string => $attempt->channel->value, $succeeded);
        $failed = array_values(array_filter($available, static fn (ChannelAttempt $attempt): bool => ! $attempt->succeeded()));
        $retryable = array_values(array_filter($failed, static fn (ChannelAttempt $attempt): bool => $attempt->retryable));
        self::storeAttemptCounts($reminder, $attempts);

        if ($succeeded !== []) {
            self::markDelivered($reminder, $now, $channels, $failed !== []);

            if ($reminder->isRecurring()) {
                $occurrenceAt = $reminder->run_at ?? $now;
                ReminderLifecycle::recordOccurrence(
                    $reminder,
                    ReminderStatus::Delivered,
                    $occurrenceAt,
                    $now,
                    $channels,
                );
                ReminderLifecycle::advanceRecurring($reminder, $occurrenceAt, $now, $calculator);
            }

            return;
        }

        if ($retryable !== []) {
            self::scheduleRetry($reminder, $failed, $now);

            return;
        }

        $error = $failed[0]->error ?? 'delivery_failed';

        if ($reminder->isRecurring()) {
            $occurrenceAt = $reminder->run_at ?? $now;
            ReminderLifecycle::recordOccurrence(
                $reminder,
                ReminderStatus::Failed,
                $occurrenceAt,
                $now,
                [],
            );
            ReminderLifecycle::advanceRecurring($reminder, $occurrenceAt, $now, $calculator);
            $metadata = self::metadata($reminder);
            $metadata['delivery_state'] = self::STATE_ERROR;
            $metadata['last_error_class'] = $error;
            $reminder->forceFill([
                'last_error' => $error,
                'metadata' => $metadata,
            ]);

            return;
        }

        self::fail($reminder, $error);
    }

    public static function deferNoChannel(Reminder $reminder, CarbonImmutable $now): void
    {
        $metadata = self::metadata($reminder);
        $metadata['delivery_state'] = self::STATE_NO_CHANNEL;
        $metadata['delivery_channel'] = null;
        $metadata['next_retry_at'] = $now->utc()->addMinutes(self::NO_CHANNEL_RECHECK_MINUTES)->toDateTimeString();
        unset($metadata['last_error_class']);

        $reminder->forceFill([
            'status' => ReminderStatus::Scheduled,
            'last_error' => null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Telegram-only bounded retry. Kept for compatibility with existing tests.
     */
    public static function retryOrFail(Reminder $reminder, string $error, CarbonImmutable $now): void
    {
        $metadata = self::metadata($reminder);
        $attempts = (int) ($metadata['attempts'] ?? 0) + 1;
        $metadata['attempts'] = $attempts;
        $metadata['last_error_class'] = $error;
        $metadata['delivery_state'] = self::STATE_ERROR;
        $metadata['delivery_channel'] = ReminderChannel::Telegram->value;

        if ($attempts < self::MAX_ATTEMPTS) {
            $metadata['next_retry_at'] = $now->utc()->addMinutes($attempts)->toDateTimeString();

            $reminder->forceFill([
                'status' => ReminderStatus::Scheduled,
                'last_error' => $error,
                'metadata' => $metadata,
            ]);

            return;
        }

        unset($metadata['next_retry_at']);

        $reminder->forceFill([
            'status' => ReminderStatus::Failed,
            'last_error' => $error,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  list<string>  $channels
     */
    public static function markDelivered(
        Reminder $reminder,
        CarbonImmutable $now,
        array $channels = [ReminderChannel::Telegram->value],
        bool $partial = false,
    ): void {
        $metadata = self::metadata($reminder);
        $metadata['delivery_state'] = $partial ? self::STATE_PARTIAL : self::STATE_DELIVERED;
        $metadata['delivery_channel'] = self::channelLabel($channels);
        unset($metadata['next_retry_at'], $metadata['last_error_class']);

        $fill = [
            'last_error' => null,
            'metadata' => $metadata,
        ];

        if (! $reminder->isRecurring()) {
            $fill['status'] = ReminderStatus::Delivered;
            $fill['delivered_at'] = $now;
        }

        $reminder->forceFill($fill);
    }

    /**
     * @param  list<ChannelAttempt>  $failed
     */
    private static function scheduleRetry(Reminder $reminder, array $failed, CarbonImmutable $now): void
    {
        $metadata = self::metadata($reminder);
        $maxAttempts = 0;

        foreach ($failed as $attempt) {
            $maxAttempts = max($maxAttempts, $attempt->attempts);
        }

        $metadata['attempts'] = $maxAttempts;
        $metadata['delivery_state'] = self::STATE_ERROR;
        $metadata['delivery_channel'] = $failed[0]->channel->value ?? null;
        $metadata['last_error_class'] = $failed[0]->error ?? 'delivery_failed';
        $metadata['next_retry_at'] = $now->utc()->addMinutes(max(1, min(self::MAX_ATTEMPTS, $maxAttempts)))->toDateTimeString();

        $reminder->forceFill([
            'status' => ReminderStatus::Scheduled,
            'last_error' => $failed[0]->error ?? 'delivery_failed',
            'metadata' => $metadata,
        ]);
    }

    private static function fail(Reminder $reminder, string $error): void
    {
        $metadata = self::metadata($reminder);
        unset($metadata['next_retry_at']);
        $metadata['delivery_state'] = self::STATE_ERROR;
        $metadata['last_error_class'] = $error;

        $reminder->forceFill([
            'status' => ReminderStatus::Failed,
            'last_error' => $error,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  list<ChannelAttempt>  $attempts
     */
    private static function storeAttemptCounts(Reminder $reminder, array $attempts): void
    {
        $metadata = self::metadata($reminder);

        foreach ($attempts as $attempt) {
            $key = $attempt->channel === ReminderChannel::Telegram ? 'telegram_attempts' : 'push_attempts';
            $metadata[$key] = $attempt->attempts;
        }

        $reminder->metadata = $metadata;
    }

    /**
     * @param  list<string>  $channels
     */
    private static function channelLabel(array $channels): ?string
    {
        $channels = array_values(array_unique($channels));

        if ($channels === []) {
            return null;
        }

        if (count($channels) === 1) {
            return $channels[0];
        }

        return 'both';
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(Reminder $reminder): array
    {
        $metadata = $reminder->metadata;

        if (! is_array($metadata)) {
            $metadata = [];
        }

        if (! array_key_exists('attempts', $metadata)) {
            $metadata['attempts'] = 0;
        }

        return $metadata;
    }
}
