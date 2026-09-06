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
use App\Services\Watchers\WatcherEvaluationService;
use App\Services\Watchers\WatcherService;

final class RunWatcherNowTool implements JarvisTool
{
    public const NAME = 'run_watcher_now';

    public function __construct(
        private readonly WatcherService $watchers,
        private readonly WatcherEvaluationService $evaluation,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Runs a check for an owned active watcher now. Does not bypass confirmation. Does not execute external writes.',
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
        return new ToolMeta(capability: UserCapability::WATCHERS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::WATCHERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $watcher = $this->watchers->requireOwned($context->user, (int) ($call->arguments['watcher_id'] ?? 0));
            $occurrence = $this->evaluation->evaluate($watcher);
        } catch (WatcherException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'watcher_id' => (int) $watcher->id,
            'matched' => $occurrence !== null,
            'occurrence_id' => $occurrence?->id,
        ]);
    }
}
