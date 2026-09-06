<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskSelection;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;

final class CompleteTaskTool implements JarvisTool
{
    public const NAME = 'complete_task';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskToolResolver $resolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Marks an owned task done. Future linked reminders are cancelled; history remains. If unfinished subtasks exist, returns open_subtasks unless force=true after the user confirms. Distinct from cancel_task.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'task_id' => ['type' => 'INTEGER'],
                    'query' => ['type' => 'STRING'],
                    'force' => ['type' => 'BOOLEAN', 'description' => 'Complete even if open subtasks exist, only after user confirmation.'],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::TASKS,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::TASKS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $resolved = $this->resolver->resolve($call, $context, $this->name());

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $force = (bool) ($call->arguments['force'] ?? false);

        try {
            $task = $this->tasks->completeOwned($context->user, (int) $resolved['task']->id, $force);
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
                'candidates' => TaskSelection::summarize($exception->candidates),
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'task_id' => (int) $task->id,
            'status' => $task->status->value,
            'completed_at' => optional($task->completed_at)?->toIso8601String(),
        ]);
    }
}
