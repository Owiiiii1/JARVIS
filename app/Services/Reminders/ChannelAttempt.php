<?php

namespace App\Services\Reminders;

use App\Enums\ReminderChannel;
use App\Enums\ReminderDeliveryStatus;

final readonly class ChannelAttempt
{
    public function __construct(
        public ReminderChannel $channel,
        public ReminderDeliveryStatus $status,
        public int $attempts,
        public bool $retryable,
        public ?string $error = null,
    ) {}

    public function available(): bool
    {
        return $this->status !== ReminderDeliveryStatus::Skipped;
    }

    public function succeeded(): bool
    {
        return $this->status === ReminderDeliveryStatus::Sent;
    }
}
