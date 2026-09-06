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

final class CancelWatcherTool implements JarvisTool
{
    public const NAME = 'cancel_watcher';

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
            description: 'Cancels an owned watcher. History is kept. Future checks stop.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'watcher_id' => ['type' => 'INTEGER'],
                ],
                'required' => ['watcher_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::WATCHERS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::WATCHERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $watcher = $this->watchers->cancelOwned($context->user, (int) ($call->arguments['watcher_id'] ?? 0));
        } catch (WatcherException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'watcher_id' => (int) $watcher->id,
            'status' => $watcher->status->value,
        ]);
    }
}
