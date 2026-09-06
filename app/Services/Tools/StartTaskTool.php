<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;

final class StartTaskTool implements JarvisTool
{
    public const NAME = 'start_task';

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
            description: 'Marks an owned open task as in progress. If several match, returns ambiguous candidates.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'task_id' => ['type' => 'INTEGER'],
                    'query' => ['type' => 'STRING'],
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

        try {
            $task = $this->tasks->startOwned($context->user, (int) $resolved['task']->id);
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'task_id' => (int) $task->id,
            'status' => $task->status->value,
        ]);
    }
}
