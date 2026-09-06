<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;

final class CreateSubtaskTool implements JarvisTool
{
    public const NAME = 'create_subtask';

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
            description: 'Creates a subtask under an owned parent task. One level only. Parent and child must belong to the current user.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'task_id' => ['type' => 'INTEGER', 'description' => 'Parent task id.'],
                    'query' => ['type' => 'STRING', 'description' => 'Unique parent title query if id is unknown.'],
                    'title' => ['type' => 'STRING'],
                    'description' => ['type' => 'STRING'],
                ],
                'required' => ['title'],
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
        $title = trim((string) ($call->arguments['title'] ?? ''));

        if ($title === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $resolved = $this->resolver->resolve($call, $context, $this->name());

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        try {
            $child = $this->tasks->addSubtask(
                $context->user,
                (int) $resolved['task']->id,
                $title,
                isset($call->arguments['description']) ? (string) $call->arguments['description'] : null,
            );
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'task_id' => (int) $child->id,
            'parent_task_id' => (int) $resolved['task']->id,
            'title' => $child->title,
        ]);
    }
}
