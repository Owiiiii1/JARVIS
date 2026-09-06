<?php

namespace App\Services\Tools;

use App\Models\Reminder;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderSelection;
use App\Services\Reminders\ReminderService;

final class ReminderToolResolver
{
    public function __construct(
        private readonly ReminderService $reminders,
    ) {}

    /**
     * @return array{ok: true, reminder: Reminder}|ToolResult
     */
    public function resolve(ToolCall $call, ToolExecutionContext $context, string $toolName): array|ToolResult
    {
        $id = isset($call->arguments['reminder_id']) ? (int) $call->arguments['reminder_id'] : null;
        $query = isset($call->arguments['query']) ? trim((string) $call->arguments['query']) : null;

        if (($id === null || $id <= 0) && ($query === null || $query === '')) {
            return ToolResult::failure($call->id, $toolName, [
                'success' => false,
                'error' => 'ambiguous',
                'message' => 'Specify reminder_id or a unique query. Call list_reminders if several reminders exist.',
            ]);
        }

        $selection = ReminderSelection::resolve(
            ($id !== null && $id > 0) ? $id : null,
            $query,
            $this->reminders->candidatesForMutation($context->user),
        );

        if ($selection['ok'] !== true) {
            return ToolResult::failure($call->id, $toolName, [
                'success' => false,
                'error' => $selection['error'],
                'candidates' => ReminderSelection::summarize($selection['candidates'] ?? []),
            ]);
        }

        return $selection;
    }
}
