<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;

final class UpdateTaskTool implements JarvisTool
{
    public const NAME = 'update_task';

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
            description: 'Updates an owned open task: title, description, priority, due date, optional Owner project, optional calendar reference. If several tasks match, returns ambiguous candidates. Never pass user_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'task_id' => ['type' => 'INTEGER'],
                    'query' => ['type' => 'STRING'],
                    'title' => ['type' => 'STRING'],
                    'description' => ['type' => 'STRING'],
                    'priority' => ['type' => 'STRING'],
                    'due_at_local' => ['type' => 'STRING'],
                    'timezone' => ['type' => 'STRING'],
                    'project_id' => ['type' => 'INTEGER'],
                    'calendar_provider' => ['type' => 'STRING'],
                    'calendar_id' => ['type' => 'STRING'],
                    'calendar_event_id' => ['type' => 'STRING'],
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

        $attributes = [];

        foreach (['title', 'description', 'priority', 'timezone', 'calendar_provider', 'calendar_id', 'calendar_event_id'] as $key) {
            if (array_key_exists($key, $call->arguments)) {
                $attributes[$key] = $call->arguments[$key];
            }
        }

        if (array_key_exists('due_at_local', $call->arguments)) {
            $attributes['due_at'] = $call->arguments['due_at_local'];
        }

        if (array_key_exists('project_id', $call->arguments)) {
            $attributes['project_id'] = $call->arguments['project_id'];
        }

        try {
            $task = $this->tasks->updateOwned($context->user, (int) $resolved['task']->id, $attributes);
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'task_id' => (int) $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => optional($task->due_at)?->toIso8601String(),
        ]);
    }
}
