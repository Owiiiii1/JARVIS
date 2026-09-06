<?php

namespace App\Services\Watchers\Clients;

use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Contracts\CalendarWatcherClient;
use App\Services\Watchers\WatcherSupport;

final class LiveCalendarWatcherClient implements CalendarWatcherClient
{
    public function __construct(
        private readonly GoogleCalendarService $calendar,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function events(User $user, array $source): array
    {
        $account = $this->accounts->getActiveAccount($user, 'google');
        if ($account === null || ! $user->canUseCapability(UserCapability::GOOGLE_CALENDAR)) {
            throw new IntegrationException('google_not_connected', 'Google Calendar is not connected.');
        }

        $calendarId = (string) ($source['calendar_id'] ?? 'primary');
        $eventId = trim((string) ($source['event_id'] ?? ''));

        if ($eventId !== '') {
            $event = $this->calendar->getEvent($account, $calendarId, $eventId);

            return [$this->compact($event)];
        }

        $result = $this->calendar->listEvents($account, $calendarId, [
            'max_results' => 20,
        ]);
        $rows = [];
        foreach ($result['events'] ?? [] as $event) {
            if (is_array($event)) {
                $rows[] = $this->compact($event);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function compact(array $event): array
    {
        return [
            'id' => (string) ($event['id'] ?? ''),
            'title' => WatcherSupport::summary((string) ($event['title'] ?? '')),
            'start' => (string) ($event['start'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'etag' => (string) ($event['etag'] ?? ($event['updated'] ?? '')),
            'updated' => (string) ($event['updated'] ?? ''),
        ];
    }
}
