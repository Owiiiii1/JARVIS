<?php

namespace App\Services\Tools;

use App\Models\Task;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tasks\TaskSelection;
use App\Services\Tasks\TaskService;

final class TaskToolResolver
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    /**
     * @return array{ok: true, task: Task}|ToolResult
     */
    public function resolve(ToolCall $call, ToolExecutionContext $context, string $toolName): array|ToolResult
    {
        $id = isset($call->arguments['task_id']) ? (int) $call->arguments['task_id'] : null;
        $query = isset($call->arguments['query']) ? trim((string) $call->arguments['query']) : null;

        if (($id === null || $id <= 0) && ($query === null || $query === '')) {
            return ToolResult::failure($call->id, $toolName, [
                'success' => false,
                'error' => 'ambiguous',
                'message' => 'Specify task_id or a unique query. Call list_tasks if several tasks exist.',
            ]);
        }

        $selection = TaskSelection::resolve(
            ($id !== null && $id > 0) ? $id : null,
            $query,
            $this->tasks->candidatesForMutation($context->user),
        );

        if ($selection['ok'] !== true) {
            return ToolResult::failure($call->id, $toolName, [
                'success' => false,
                'error' => $selection['error'],
                'candidates' => TaskSelection::summarize($selection['candidates'] ?? []),
            ]);
        }

        return $selection;
    }
}
