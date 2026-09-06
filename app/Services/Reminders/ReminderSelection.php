<?php

namespace App\Services\Reminders;

use App\Models\Reminder;

final class ReminderSelection
{
    /**
     * @param  list<Reminder>  $candidates
     * @return array{ok: true, reminder: Reminder}|array{ok: false, error: string, candidates?: list<Reminder>}
     */
    public static function resolve(?int $id, ?string $query, array $candidates): array
    {
        if ($id !== null && $id > 0) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate->id === $id) {
                    return ['ok' => true, 'reminder' => $candidate];
                }
            }

            return ['ok' => false, 'error' => 'not_found'];
        }

        $needle = mb_strtolower(trim((string) $query));

        if ($needle === '') {
            if (count($candidates) === 1) {
                return ['ok' => true, 'reminder' => $candidates[0]];
            }

            return [
                'ok' => false,
                'error' => count($candidates) === 0 ? 'not_found' : 'ambiguous',
                'candidates' => $candidates,
            ];
        }

        $matched = [];

        foreach ($candidates as $candidate) {
            if (str_contains(mb_strtolower((string) $candidate->text), $needle)) {
                $matched[] = $candidate;
            }
        }

        if (count($matched) === 1) {
            return ['ok' => true, 'reminder' => $matched[0]];
        }

        if ($matched === []) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        return ['ok' => false, 'error' => 'ambiguous', 'candidates' => $matched];
    }

    /**
     * @param  list<Reminder>  $reminders
     * @return list<array{id: int, text: string, run_at: ?string, status: string}>
     */
    public static function summarize(array $reminders): array
    {
        return array_map(static function (Reminder $reminder): array {
            return [
                'id' => (int) $reminder->id,
                'text' => $reminder->text,
                'run_at' => optional($reminder->run_at)?->toIso8601String(),
                'status' => $reminder->status->value,
            ];
        }, $reminders);
    }
}
