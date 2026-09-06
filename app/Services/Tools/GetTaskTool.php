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

final class GetTaskTool implements JarvisTool
{
    public const NAME = 'get_task';

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
            description: 'Reads one owned task. Pass task_id or a unique query. If several match, returns ambiguous candidates.',
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
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::TASKS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = isset($call->arguments['task_id']) ? (int) $call->arguments['task_id'] : 0;

        if ($id > 0) {
            try {
                $task = $this->tasks->requireOwned($context->user, $id);
            } catch (TaskException $exception) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => $exception->error,
                ]);
            }

            return ToolResult::success($call->id, $this->name(), [
                'success' => true,
                'task' => TaskSelection::summarize([$task])[0],
            ]);
        }

        $resolved = $this->resolver->resolve($call, $context, $this->name());

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        try {
            $task = $this->tasks->requireOwned($context->user, (int) $resolved['task']->id);
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'task' => TaskSelection::summarize([$task])[0],
        ]);
    }
}
