<?php

namespace App\Services\Reminders;

use App\Enums\WebPushSendOutcome;

final readonly class WebPushSendResult
{
    public function __construct(
        public WebPushSendOutcome $outcome,
        public ?string $error = null,
        public ?int $statusCode = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === WebPushSendOutcome::Sent;
    }

    public function gone(): bool
    {
        return $this->outcome === WebPushSendOutcome::Gone;
    }

    public function retryable(): bool
    {
        return $this->outcome === WebPushSendOutcome::Transient;
    }
}
