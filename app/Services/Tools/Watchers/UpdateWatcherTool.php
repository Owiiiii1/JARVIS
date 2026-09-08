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
use App\Services\Watchers\WatcherSchedule;
use App\Services\Watchers\WatcherService;

final class UpdateWatcherTool implements JarvisTool
{
    public const NAME = 'update_watcher';

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
            description: 'Updates an owned watcher. Use this to add another Gmail sender/domain to an existing Gmail event watcher. Changing source/condition resets the baseline so historical items are not replayed. Never pass user_id or integration_account_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'watcher_id' => ['type' => 'INTEGER', 'description' => 'Owned watcher id.'],
                    'name' => ['type' => 'STRING'],
                    'trigger_type' => ['type' => 'STRING'],
                    'condition_type' => ['type' => 'STRING'],
                    'reaction_type' => ['type' => 'STRING'],
                    'source' => ['type' => 'OBJECT'],
                    'condition' => ['type' => 'OBJECT'],
                    'reaction_config' => ['type' => 'OBJECT'],
                    'cooldown_seconds' => ['type' => 'INTEGER'],
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
            $watcher = $this->watchers->updateOwned($context->user, (int) ($call->arguments['watcher_id'] ?? 0), $call->arguments);
        } catch (WatcherException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'watcher_id' => (int) $watcher->id,
            'status' => $watcher->status->value,
            'description' => $this->watchers->serialize($watcher, (string) ($context->user->timezone ?: 'UTC'))['description'] ?? null,
            'trigger_type' => $watcher->trigger_type->value,
            'kind' => $watcher->trigger_type->value === 'gmail_message'
                ? (WatcherSchedule::isDigest($watcher) ? 'gmail_digest' : 'gmail_event')
                : 'other',
        ]);
    }
}
