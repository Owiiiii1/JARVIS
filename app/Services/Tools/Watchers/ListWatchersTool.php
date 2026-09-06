<?php

namespace App\Services\Tools\Watchers;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\WatcherService;

final class ListWatchersTool implements JarvisTool
{
    public const NAME = 'list_watchers';

    public function __construct(
        private readonly WatcherService $watchers,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists the current user’s watchers. Optional status filter: active, paused, completed, failed, cancelled.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'status' => ['type' => 'STRING', 'description' => 'Optional status filter.'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::WATCHERS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::WATCHERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $items = $this->watchers->listFor($context->user, isset($call->arguments['status']) ? (string) $call->arguments['status'] : null)
                ->map(fn ($watcher): array => $this->watchers->serialize($watcher))
                ->values()
                ->all();
        } catch (WatcherException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($items),
            'watchers' => $items,
        ]);
    }
}
