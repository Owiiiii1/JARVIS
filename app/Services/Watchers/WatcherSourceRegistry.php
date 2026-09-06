<?php

namespace App\Services\Watchers;

use App\Enums\WatcherTriggerType;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\Exceptions\WatcherException;

final class WatcherSourceRegistry
{
    /**
     * @param  list<WatcherSourceAdapter>  $adapters
     */
    public function __construct(
        private readonly array $adapters,
    ) {}

    public function adapterFor(Watcher $watcher): WatcherSourceAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($watcher)) {
                return $adapter;
            }
        }

        throw new WatcherException('unsupported_source', 'Watcher source is not supported.');
    }

    public static function cadenceSeconds(WatcherTriggerType $type): int
    {
        return match ($type) {
            WatcherTriggerType::GmailMessage => max(60, (int) config('watchers.cadence.gmail_seconds', 480)),
            WatcherTriggerType::GithubEvent => max(60, (int) config('watchers.cadence.github_seconds', 480)),
            WatcherTriggerType::CalendarEvent => max(60, (int) config('watchers.cadence.calendar_seconds', 300)),
            default => max(60, (int) config('watchers.cadence.internal_seconds', 300)),
        };
    }
}
