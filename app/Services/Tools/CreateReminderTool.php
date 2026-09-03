<?php

namespace App\Services\Tools;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;

final class CreateReminderTool implements JarvisTool
{
    public const NAME = 'create_reminder';

    public function __construct(
        private readonly ReminderService $reminders,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Создаёт персональное напоминание пользователя с доставкой в Telegram.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'text' => [
                        'type' => 'STRING',
                        'description' => 'What to remind the user about, without the time phrase.',
                    ],
                    'run_at_local' => [
                        'type' => 'STRING',
                        'description' => 'ISO 8601 datetime with offset for the reminder instant, e.g. 2026-09-04T11:00:00+02:00.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'IANA timezone of the user, e.g. Europe/Rome.',
                    ],
                    'original_time_expression' => [
                        'type' => 'STRING',
                        'description' => 'Optional original time phrase from the user.',
                    ],
                ],
                'required' => ['text', 'run_at_local'],
            ],
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::REMINDERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $text = trim((string) ($call->arguments['text'] ?? ''));
        $runAtLocal = trim((string) ($call->arguments['run_at_local'] ?? ''));
        $timezone = (string) $context->user->timezone;

        if ($text === '' || $runAtLocal === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        if (isset($call->arguments['recurrence_rule']) || isset($call->arguments['recurrence'])) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'unsupported_recurrence',
            ]);
        }

        try {
            $runAtUtc = $this->reminders->localWallTimeToUtc($runAtLocal, $timezone);
            $local = $runAtUtc->setTimezone($timezone);

            $reminder = $this->reminders->create(
                user: $context->user,
                text: $text,
                runAt: $runAtUtc,
                timezone: $timezone,
                conversation: $context->conversation,
                sourceMessage: $context->inbound,
            );

            return ToolResult::success($call->id, $this->name(), [
                'success' => true,
                'reminder_id' => $reminder->id,
                'text' => $reminder->text,
                'run_at_local' => $local->format('Y-m-d\TH:i:sP'),
                'timezone' => $timezone,
            ]);
        } catch (ReminderException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }
    }
}
