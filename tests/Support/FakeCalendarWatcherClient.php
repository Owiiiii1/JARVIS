<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Watchers\Contracts\CalendarWatcherClient;

final class FakeCalendarWatcherClient implements CalendarWatcherClient
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public int $calls = 0;

    public ?\Throwable $exception = null;

    public function events(User $user, array $source): array
    {
        $this->calls++;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->events;
    }
}
