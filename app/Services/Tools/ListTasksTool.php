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

final class ListTasksTool implements JarvisTool
{
    public const NAME = 'list_tasks';

    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists the current user’s tasks. Use before mutating a task when the target is unclear. Never guess among several matches.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING', 'description' => 'Optional title search.'],
                    'limit' => ['type' => 'INTEGER', 'description' => 'Optional maximum. Core caps this.'],
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
        $limit = isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : 12;
        $query = isset($call->arguments['query']) ? trim((string) $call->arguments['query']) : null;

        try {
            $items = TaskSelection::summarize($this->tasks->searchOwned($context->user, $query, $limit)->all());
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'tasks' => $items,
        ]);
    }
}
