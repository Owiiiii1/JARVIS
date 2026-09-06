<?php

namespace App\Services\Productivity;

use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProductivitySnapshot
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function promptLines(User $user): ?string
    {
        if (! Schema::hasTable('tasks')) {
            return null;
        }

        try {
            $snapshot = $this->tasks->snapshotFor($user, 3);
        } catch (Throwable) {
            return null;
        }

        if ($snapshot['overdue'] === 0 && $snapshot['due_today'] === 0 && $snapshot['urgent'] === []) {
            return null;
        }

        $lines = [
            'Productivity snapshot (bounded, not a full task dump):',
            'Overdue tasks: '.$snapshot['overdue'].'. Due today: '.$snapshot['due_today'].'.',
        ];

        if ($snapshot['urgent'] !== []) {
            $titles = array_map(static fn (array $task): string => $task['title'], $snapshot['urgent']);
            $lines[] = 'Top relevant: '.implode('; ', $titles).'.';
        }

        $lines[] = 'Use task tools for details. Do not invent tasks. Do not auto-create a task from vague “надо бы” without a clear commitment or explicit request.';

        return implode("\n", $lines);
    }
}
