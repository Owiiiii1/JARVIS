<?php

namespace App\Services\Watchers;

use App\Enums\WatcherConditionType;
use App\Models\Watcher;
use App\Services\Watchers\DTO\WatcherMatch;
use App\Services\Watchers\DTO\WatcherObservation;

final class WatcherConditionEvaluator
{
    public function evaluate(Watcher $watcher, WatcherObservation $observation): WatcherMatch
    {
        $config = is_array($watcher->condition_config) ? $watcher->condition_config : [];

        return match ($watcher->condition_type) {
            WatcherConditionType::EventExists, WatcherConditionType::NewItem => new WatcherMatch(true, $watcher->condition_type->value, [
                'source_id' => $observation->sourceId,
            ]),
            WatcherConditionType::EntityEventType => $this->entityEvent($config, $observation),
            WatcherConditionType::StatusEquals => $this->stringField($config, $observation, 'status', 'expected'),
            WatcherConditionType::StatusChanged => $this->statusChanged($config, $observation),
            WatcherConditionType::DeadlineWithin, WatcherConditionType::OverdueBy => new WatcherMatch(true, $watcher->condition_type->value, [
                'task_id' => $observation->taskId,
            ]),
            WatcherConditionType::SenderMatches => $this->containsField($config, $observation, 'sender', ['sender', 'from', 'email']),
            WatcherConditionType::SubjectContains => $this->containsField($config, $observation, 'subject', ['needle', 'contains', 'query']),
            WatcherConditionType::ThreadReceivedReply => $this->threadReply($config, $observation),
            WatcherConditionType::CalendarChanged => $this->calendarChanged($observation),
            WatcherConditionType::GithubNewCommit => new WatcherMatch($observation->eventType === 'github_commit_seen', 'github_new_commit', [
                'sha' => $observation->sourceId,
            ]),
            WatcherConditionType::GithubPrStateChanged => new WatcherMatch($observation->eventType === 'github_pr_state_changed', 'github_pr_state_changed', [
                'pr' => $observation->sourceId,
            ]),
            WatcherConditionType::GithubWorkflowFailed => new WatcherMatch($observation->eventType === 'github_workflow_failed', 'github_workflow_failed', [
                'run' => $observation->sourceId,
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function entityEvent(array $config, WatcherObservation $observation): WatcherMatch
    {
        $allowed = $this->stringList($config['event_types'] ?? ($config['event_type'] ?? null));

        if ($allowed === []) {
            return new WatcherMatch(true, 'entity_event_type', ['event_type' => $observation->eventType]);
        }

        $ok = in_array($observation->eventType, $allowed, true);

        return new WatcherMatch($ok, $ok ? 'entity_event_type' : 'event_type_mismatch', [
            'event_type' => $observation->eventType,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function stringField(array $config, WatcherObservation $observation, string $metaKey, string $expectedKey): WatcherMatch
    {
        $expected = mb_strtolower(trim((string) ($config[$expectedKey] ?? $config['status'] ?? '')));
        $actual = mb_strtolower(trim((string) ($observation->metadata[$metaKey] ?? '')));
        $ok = $expected !== '' && $actual === $expected;

        return new WatcherMatch($ok, $ok ? 'status_equals' : 'status_mismatch', [
            'expected' => $expected,
            'actual' => $actual,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function statusChanged(array $config, WatcherObservation $observation): WatcherMatch
    {
        $from = mb_strtolower(trim((string) ($config['from'] ?? '')));
        $to = mb_strtolower(trim((string) ($config['to'] ?? $config['status'] ?? '')));
        $actualFrom = mb_strtolower(trim((string) ($observation->metadata['previous_status'] ?? '')));
        $actualTo = mb_strtolower(trim((string) ($observation->metadata['status'] ?? '')));
        $ok = $actualTo !== '' && ($to === '' || $actualTo === $to) && ($from === '' || $actualFrom === $from);

        return new WatcherMatch($ok, $ok ? 'status_changed' : 'status_unchanged', [
            'from' => $actualFrom,
            'to' => $actualTo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $keys
     */
    private function containsField(array $config, WatcherObservation $observation, string $metaKey, array $keys): WatcherMatch
    {
        $needle = '';
        foreach ($keys as $key) {
            if (isset($config[$key]) && trim((string) $config[$key]) !== '') {
                $needle = mb_strtolower(trim((string) $config[$key]));
                break;
            }
        }

        $haystack = mb_strtolower(trim((string) ($observation->metadata[$metaKey] ?? '')));
        $ok = $needle !== '' && ($haystack === $needle || str_contains($haystack, $needle));

        return new WatcherMatch($ok, $ok ? $metaKey.'_matches' : $metaKey.'_mismatch', [
            $metaKey => $haystack,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function threadReply(array $config, WatcherObservation $observation): WatcherMatch
    {
        $thread = (string) ($config['thread_id'] ?? '');
        $actual = (string) ($observation->metadata['thread_id'] ?? $observation->sourceId);
        $ok = $thread === '' || $thread === $actual;

        return new WatcherMatch($ok && $observation->eventType === 'email_received', $ok ? 'thread_received_reply' : 'thread_mismatch', [
            'thread_id' => $actual,
        ]);
    }

    private function calendarChanged(WatcherObservation $observation): WatcherMatch
    {
        $ok = in_array($observation->eventType, ['calendar_changed', 'calendar_cancelled', 'calendar_event'], true);

        return new WatcherMatch($ok, $ok ? 'calendar_changed' : 'calendar_unchanged', [
            'event_id' => $observation->sourceId,
        ]);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [mb_strtolower(trim($value))];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = mb_strtolower(trim($item));
            }
        }

        return array_values(array_unique($items));
    }
}
