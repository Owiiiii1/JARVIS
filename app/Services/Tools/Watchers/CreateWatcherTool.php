<?php

namespace App\Services\Tools\Watchers;

use App\Enums\ToolOperationClass;
use App\Enums\WatcherCreatedBy;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\WatcherService;

final class CreateWatcherTool implements JarvisTool
{
    public const NAME = 'create_watcher';

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
            description: 'Creates an explicit Jarvis watcher for a future condition/event (not a reminder). Use when the user asked to be told when something happens. Resolve stable ids. One-shot vs recurring must be explicit. Does not scan historical inbox/repo/calendar.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING', 'description' => 'Short watcher name.'],
                    'trigger_type' => ['type' => 'STRING', 'description' => 'knowledge_event, task_state, reminder_state, time_condition, calendar_event, gmail_message, github_event.'],
                    'source_type' => ['type' => 'STRING', 'description' => 'knowledge_entity, project, task, reminder, gmail, calendar, github, time.'],
                    'condition_type' => ['type' => 'STRING', 'description' => 'Controlled condition, e.g. thread_received_reply, github_new_commit, overdue_by, entity_event_type.'],
                    'reaction_type' => ['type' => 'STRING', 'description' => 'notify, create_notification, create_reminder, create_task, run_internal_analysis, propose_action.'],
                    'mode' => ['type' => 'STRING', 'description' => 'one_shot or recurring.'],
                    'task_id' => ['type' => 'INTEGER', 'description' => 'Owned task id when watching a task/deadline.'],
                    'knowledge_entity_id' => ['type' => 'INTEGER', 'description' => 'Owned knowledge entity id when watching a timeline.'],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id when scoped.'],
                    'entity_name' => ['type' => 'STRING', 'description' => 'Fallback name/alias to resolve a knowledge entity, e.g. YFS.'],
                    'cooldown_seconds' => ['type' => 'INTEGER', 'description' => 'Minimum seconds between notifications.'],
                    'source' => ['type' => 'OBJECT', 'description' => 'Bounded source filters: query, sender, thread_id, repository, branch, event_id, calendar_id, event_type.'],
                    'condition' => ['type' => 'OBJECT', 'description' => 'Bounded condition config: hours, status, event_type, sender, subject.'],
                    'reaction_config' => ['type' => 'OBJECT', 'description' => 'Bounded reaction config. For propose_action include tool name only — never execute it.'],
                ],
                'required' => ['name', 'trigger_type', 'condition_type'],
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
            $input = $call->arguments;
            $input['conversation_id'] = $context->conversation->id;
            $watcher = $this->watchers->create($context->user, $input, WatcherCreatedBy::Tool);
        } catch (WatcherException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
                'candidates' => $exception->candidates,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'watcher_id' => (int) $watcher->id,
            'status' => $watcher->status->value,
            'mode' => $watcher->mode->value,
            'name' => $watcher->name,
        ]);
    }
}
