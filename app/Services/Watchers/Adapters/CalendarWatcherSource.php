<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\WatcherTriggerType;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\CalendarWatcherClient;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class CalendarWatcherSource implements WatcherSourceAdapter
{
    public function __construct(
        private readonly CalendarWatcherClient $calendar,
    ) {}

    public function supports(Watcher $watcher): bool
    {
        return $watcher->trigger_type === WatcherTriggerType::CalendarEvent;
    }

    public function check(User $user, Watcher $watcher): array
    {
        $source = is_array($watcher->source_config) ? $watcher->source_config : [];
        $rows = $this->calendar->events($user, $source);
        $observations = [];
        $seen = is_array(($watcher->cursor ?? [])['seen'] ?? null) ? ($watcher->cursor['seen']) : [];

        foreach (array_slice($rows, 0, max(1, (int) config('watchers.limits.max_observations', 20))) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $hash = (string) ($row['etag'] ?? ($row['updated'] ?? ($row['start'] ?? '')));
            $previous = is_string($seen[$id] ?? null) ? (string) $seen[$id] : null;
            $eventType = 'calendar_event';
            if (($row['status'] ?? '') === 'cancelled') {
                $eventType = 'calendar_cancelled';
            } elseif ($previous !== null && $previous !== $hash) {
                $eventType = 'calendar_changed';
            }

            $observations[] = new WatcherObservation(
                sourceType: 'calendar',
                sourceId: $id,
                eventType: $eventType,
                fingerprint: WatcherSupport::fingerprint('calendar', (string) $watcher->id, $id, $hash),
                occurredAt: isset($row['updated']) ? CarbonImmutable::parse((string) $row['updated'])->utc() : CarbonImmutable::now('UTC'),
                title: WatcherSupport::summary((string) ($row['title'] ?? 'Calendar event')),
                metadata: WatcherSupport::boundMetadata([
                    'start' => (string) ($row['start'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'etag' => $hash,
                ]),
                projectId: $watcher->project_id,
            );
        }

        return $observations;
    }
}
