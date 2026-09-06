<?php

namespace App\Services\Reminders;

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

    public static function retryOrFail(Reminder $reminder, string $error, CarbonImmutable $now): void
    {
        $metadata = self::metadata($reminder);
        $attempts = (int) ($metadata['attempts'] ?? 0) + 1;
        $metadata['attempts'] = $attempts;
        $metadata['last_error_class'] = $error;
        $metadata['delivery_state'] = self::STATE_ERROR;
        $metadata['delivery_channel'] = 'telegram';

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

    public static function markDelivered(Reminder $reminder, CarbonImmutable $now): void
    {
        $metadata = self::metadata($reminder);
        $metadata['delivery_state'] = self::STATE_DELIVERED;
        $metadata['delivery_channel'] = 'telegram';
        unset($metadata['next_retry_at'], $metadata['last_error_class']);

        $reminder->forceFill([
            'status' => ReminderStatus::Delivered,
            'delivered_at' => $now,
            'last_error' => null,
            'metadata' => $metadata,
        ]);
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
