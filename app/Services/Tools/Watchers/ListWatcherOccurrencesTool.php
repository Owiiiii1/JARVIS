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

final class ListWatcherOccurrencesTool implements JarvisTool
{
    public const NAME = 'list_watcher_occurrences';

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
            description: 'Lists recent occurrences for an owned watcher (what matched, when, reaction).',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'watcher_id' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
                ],
                'required' => ['watcher_id'],
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
            $items = $this->watchers->occurrencesFor(
                $context->user,
                (int) ($call->arguments['watcher_id'] ?? 0),
                isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : 20,
            );
        } catch (WatcherException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($items),
            'occurrences' => $items,
        ]);
    }
}
