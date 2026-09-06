<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use Carbon\CarbonImmutable;

final class ReminderLifecycle
{
    /**
     * @return list<ReminderStatus>
     */
    public static function openStatuses(): array
    {
        return [ReminderStatus::Scheduled, ReminderStatus::Processing];
    }

    public static function isOpen(Reminder $reminder): bool
    {
        return in_array($reminder->status, self::openStatuses(), true);
    }

    public static function isEditable(Reminder $reminder): bool
    {
        return self::isOpen($reminder);
    }

    public static function isSnoozable(Reminder $reminder): bool
    {
        return self::isOpen($reminder);
    }

    public static function isCompletable(Reminder $reminder): bool
    {
        return self::isOpen($reminder) || $reminder->status === ReminderStatus::Delivered;
    }

    public static function resetDelivery(Reminder $reminder): void
    {
        $metadata = is_array($reminder->metadata) ? $reminder->metadata : [];
        $metadata['attempts'] = 0;
        unset(
            $metadata['next_retry_at'],
            $metadata['last_error_class'],
            $metadata['delivery_state'],
            $metadata['delivery_channel'],
            $metadata['telegram_attempts'],
            $metadata['push_attempts'],
        );

        $reminder->forceFill([
            'status' => ReminderStatus::Scheduled,
            'last_error' => null,
            'delivered_at' => null,
            'metadata' => $metadata,
        ]);
    }

    public static function applySchedule(
        Reminder $reminder,
        CarbonImmutable $runAtUtc,
        string $timezone,
        ?string $recurrence = null,
    ): void {
        $local = $runAtUtc->utc()->setTimezone($timezone);

        $reminder->forceFill([
            'run_at' => $runAtUtc->utc(),
            'timezone' => $timezone,
            'original_local_time' => $local->format('Y-m-d\TH:i:sP'),
            'recurrence_rule' => $recurrence,
        ]);

        self::resetDelivery($reminder);
    }

    public static function markCompleted(Reminder $reminder, CarbonImmutable $now): void
    {
        $reminder->forceFill([
            'status' => ReminderStatus::Completed,
            'completed_at' => $now->utc(),
            'last_error' => null,
        ]);
    }

    public static function markCancelled(Reminder $reminder, CarbonImmutable $now): void
    {
        $reminder->forceFill([
            'status' => ReminderStatus::Cancelled,
            'cancelled_at' => $now->utc(),
        ]);
    }

    /**
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    public static function recordOccurrence(
        Reminder $reminder,
        ReminderStatus $status,
        CarbonImmutable $occurrenceAt,
        CarbonImmutable $now,
        array $channels = [],
    ): array {
        $snapshot = [
            'run_at' => $occurrenceAt->utc()->toIso8601String(),
            'status' => $status->value,
            'channels' => $channels,
            'recorded_at' => $now->utc()->toIso8601String(),
        ];

        $metadata = is_array($reminder->metadata) ? $reminder->metadata : [];
        $history = is_array($metadata['occurrence_history'] ?? null) ? $metadata['occurrence_history'] : [];
        $history[] = $snapshot;
        $metadata['occurrence_history'] = array_slice($history, -50);
        $metadata['last_occurrence'] = $snapshot;
        $metadata['pending_occurrence'] = $snapshot;
        $reminder->metadata = $metadata;

        return $snapshot;
    }

    public static function advanceRecurring(
        Reminder $reminder,
        CarbonImmutable $fromUtc,
        CarbonImmutable $now,
        ReminderRecurrenceCalculator $calculator,
    ): CarbonImmutable {
        $rule = ReminderRecurrenceCalculator::parse($reminder->recurrence_rule);

        if ($rule === null) {
            throw new ReminderException('invalid_recurrence', 'Recurrence rule is invalid.');
        }

        $timezone = (string) ($reminder->timezone ?: 'UTC');
        $next = $calculator->nextRunAt($fromUtc, $timezone, $rule);
        self::applySchedule($reminder, $next, $timezone, $rule->value);

        return $next;
    }

    public static function snoozeTo(Reminder $reminder, CarbonImmutable $runAtUtc, string $timezone): void
    {
        $recurrence = ReminderRecurrenceCalculator::normalize($reminder->recurrence_rule);
        self::applySchedule($reminder, $runAtUtc, $timezone, $recurrence);
    }

    public static function resolveSnoozeAt(
        Reminder $reminder,
        string $preset,
        CarbonImmutable $now,
        ?CarbonImmutable $customUtc = null,
    ): CarbonImmutable {
        $timezone = (string) ($reminder->timezone ?: 'UTC');

        return match ($preset) {
            '10m' => $now->utc()->addMinutes(10),
            '1h' => $now->utc()->addHour(),
            'tomorrow' => self::tomorrowSameWallClock($reminder, $now),
            'custom' => $customUtc ?? throw new ReminderException('invalid_time', 'Custom snooze time is required.'),
            default => throw new ReminderException('invalid_snooze', 'Unknown snooze preset.'),
        };
    }

    public static function tomorrowSameWallClock(Reminder $reminder, CarbonImmutable $now): CarbonImmutable
    {
        $timezone = (string) ($reminder->timezone ?: 'UTC');
        $local = ($reminder->run_at ?? $now)->utc()->setTimezone($timezone);
        $nowLocal = $now->utc()->setTimezone($timezone);
        $target = $nowLocal->addDay()->setTime(
            (int) $local->format('H'),
            (int) $local->format('i'),
            (int) $local->format('s'),
        );

        $utc = CarbonImmutable::create(
            (int) $target->format('Y'),
            (int) $target->format('m'),
            (int) $target->format('d'),
            (int) $local->format('H'),
            (int) $local->format('i'),
            (int) $local->format('s'),
            $timezone,
        )->utc();

        if (! $utc->greaterThan($now->utc())) {
            $utc = $utc->addDay();
        }

        return $utc;
    }
}
