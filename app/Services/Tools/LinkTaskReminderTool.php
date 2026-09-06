<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;

final class LinkTaskReminderTool implements JarvisTool
{
    public const NAME = 'link_task_reminder';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ReminderService $reminders,
        private readonly TaskToolResolver $taskResolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Links a reminder to an owned task. Either create a new reminder with run_at_local, or attach an existing owned reminder_id. The reminder text may reuse the task title; do not duplicate endlessly.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'task_id' => ['type' => 'INTEGER'],
                    'query' => ['type' => 'STRING', 'description' => 'Unique task query if id is unknown.'],
                    'run_at_local' => ['type' => 'STRING', 'description' => 'Create a new linked reminder at this local time.'],
                    'timezone' => ['type' => 'STRING'],
                    'reminder_id' => ['type' => 'INTEGER', 'description' => 'Existing owned reminder to attach.'],
                    'text' => ['type' => 'STRING', 'description' => 'Optional reminder text. Defaults to the task title.'],
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
            && $context->user->canUseCapability(UserCapability::TASKS)
            && $context->user->canUseCapability(UserCapability::REMINDERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $resolved = $this->taskResolver->resolve($call, $context, $this->name());

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        try {
            $task = $this->tasks->requireOwned($context->user, (int) $resolved['task']->id);
            $reminderId = isset($call->arguments['reminder_id']) ? (int) $call->arguments['reminder_id'] : 0;

            if ($reminderId > 0) {
                $reminder = $this->reminders->linkOwnedTask($context->user, $reminderId, (int) $task->id);
            } else {
                $runAtLocal = trim((string) ($call->arguments['run_at_local'] ?? ''));

                if ($runAtLocal === '') {
                    return ToolResult::failure($call->id, $this->name(), [
                        'success' => false,
                        'error' => 'invalid_arguments',
                    ]);
                }

                $timezone = trim((string) ($call->arguments['timezone'] ?? $context->user->timezone ?: 'UTC'));
                $text = trim((string) ($call->arguments['text'] ?? $task->title));
                $runAt = $this->reminders->localWallTimeToUtc($runAtLocal, $timezone);
                $reminder = $this->reminders->create(
                    user: $context->user,
                    text: $text !== '' ? $text : (string) $task->title,
                    runAt: $runAt,
                    timezone: $timezone,
                    conversation: $context->conversation,
                    sourceMessage: $context->inbound,
                    taskId: (int) $task->id,
                );
            }
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        } catch (ReminderException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'task_id' => (int) $task->id,
            'reminder_id' => (int) $reminder->id,
        ]);
    }
}
