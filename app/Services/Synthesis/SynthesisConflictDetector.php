<?php

namespace App\Services\Synthesis;

use App\Enums\KnowledgeEventType;
use App\Models\KnowledgeEvent;
use App\Models\Task;
use App\Services\Synthesis\DTO\FactPack;
use App\Services\Synthesis\DTO\SourceRef;
use App\Services\Tasks\TaskLifecycle;

final class SynthesisConflictDetector
{
    /**
     * @return list<array<string, mixed>>
     */
    public function detect(FactPack $pack): array
    {
        $conflicts = [];
        $tasksById = [];

        foreach ($pack->tasks as $task) {
            if ($task instanceof Task) {
                $tasksById[(int) $task->id] = $task;
            }
        }

        foreach ($pack->events as $event) {
            if (! $event instanceof KnowledgeEvent) {
                continue;
            }

            $metadata = $event->metadata ?? [];
            $taskId = isset($metadata['task_id']) ? (int) $metadata['task_id'] : 0;
            $task = $taskId > 0 ? ($tasksById[$taskId] ?? null) : null;

            if ($event->type === KnowledgeEventType::TaskCompleted && $task instanceof Task && TaskLifecycle::isOpen($task)) {
                $conflicts[] = [
                    'message' => 'Есть противоречивые данные: Knowledge marks the task completed, Task domain still has it open.',
                    'authority' => 'task',
                    'resolved_as' => $task->status->value,
                    'sources' => [
                        (new SourceRef(taskId: (int) $task->id, domain: 'task'))->toArray(),
                        (new SourceRef(knowledgeEventId: (int) $event->id, domain: 'knowledge'))->toArray(),
                    ],
                ];
            }
        }

        $claims = [];

        foreach ($pack->events as $event) {
            if (! $event instanceof KnowledgeEvent) {
                continue;
            }

            $metadata = $event->metadata ?? [];
            $key = (string) ($metadata['claim_key'] ?? '');
            $value = (string) ($metadata['claim_value'] ?? '');

            if ($key === '' || $value === '') {
                continue;
            }

            $claims[$key][(string) $event->id] = [
                'value' => $value,
                'event_id' => (int) $event->id,
            ];
        }

        foreach ($claims as $key => $values) {
            $unique = array_unique(array_column($values, 'value'));

            if (count($unique) < 2) {
                continue;
            }

            $sources = [];

            foreach ($values as $row) {
                $sources[] = (new SourceRef(knowledgeEventId: (int) $row['event_id'], domain: 'knowledge'))->toArray();
            }

            $conflicts[] = [
                'message' => 'Есть противоречивые данные about '.$key.'.',
                'authority' => null,
                'resolved_as' => null,
                'sources' => $sources,
            ];
        }

        return $conflicts;
    }
}
