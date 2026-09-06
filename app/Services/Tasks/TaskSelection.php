<?php

namespace App\Services\Tasks;

use App\Models\Task;

final class TaskSelection
{
    /**
     * @param  list<Task>  $candidates
     * @return array{ok: true, task: Task}|array{ok: false, error: string, candidates?: list<Task>}
     */
    public static function resolve(?int $id, ?string $query, array $candidates): array
    {
        if ($id !== null && $id > 0) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate->id === $id) {
                    return ['ok' => true, 'task' => $candidate];
                }
            }

            return ['ok' => false, 'error' => 'not_found'];
        }

        $needle = mb_strtolower(trim((string) $query));

        if ($needle === '') {
            if (count($candidates) === 1) {
                return ['ok' => true, 'task' => $candidates[0]];
            }

            return [
                'ok' => false,
                'error' => count($candidates) === 0 ? 'not_found' : 'ambiguous',
                'candidates' => $candidates,
            ];
        }

        $matched = [];

        foreach ($candidates as $candidate) {
            $haystack = mb_strtolower(trim((string) $candidate->title).' '.trim((string) $candidate->description));

            if (str_contains($haystack, $needle)) {
                $matched[] = $candidate;
            }
        }

        if (count($matched) === 1) {
            return ['ok' => true, 'task' => $matched[0]];
        }

        if ($matched === []) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        return ['ok' => false, 'error' => 'ambiguous', 'candidates' => $matched];
    }

    /**
     * @param  list<Task>  $tasks
     * @return list<array{id: int, title: string, status: string, priority: string, due_at: ?string}>
     */
    public static function summarize(array $tasks): array
    {
        return array_map(static function (Task $task): array {
            return [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'due_at' => optional($task->due_at)?->toIso8601String(),
            ];
        }, $tasks);
    }
}
