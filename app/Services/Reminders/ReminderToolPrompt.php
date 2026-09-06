<?php

namespace App\Services\Reminders;

use App\Services\Tools\CancelReminderTool;
use App\Services\Tools\CompleteReminderTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ListRemindersTool;
use App\Services\Tools\SnoozeReminderTool;
use App\Services\Tools\UpdateReminderTool;

final class ReminderToolPrompt
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'create_reminder creates a Jarvis Core reminder. Call it when the user asks to be reminded and the time is exact (clock time or a relative duration such as "in 2 minutes"). Telegram and Web Push are optional independent delivery adapters, not a create requirement.',
            'If the notification depends on a future state or event (“если завтра всё ещё не готово”, “когда Apple ответит”), use a watcher instead of create_reminder.',
            'Only call create_reminder when the current user message is itself a reminder request. Follow-ups such as "ты тут?" are not reminder requests.',
            'If the day is known but the clock time is missing, ask "Во сколько напомнить?" and do not call the tool. Do not invent 09:00 or another default time.',
            'Dayparts such as "tomorrow morning" without a clock time are not exact — ask.',
            'Recurrence values: daily, weekdays, weekly, monthly. Use create_reminder with recurrence when the user asks to be reminded every weekday at 8, every day, weekly, or monthly.',
            'list_reminders lists open reminders. Call it when the user refers to a reminder without a unique identity.',
            'update_reminder changes text, time, timezone, or recurrence. snooze_reminder delays it (10m, 1h, tomorrow, custom). complete_reminder marks it done by the user. cancel_reminder stops it. Done and cancel are different.',
            'If several reminders could match ("перенеси напоминание на завтра"), call list_reminders and ask which one. Never update a random reminder. Pass reminder_id when known.',
            'After a successful create_reminder, confirm in natural language using the returned local time. If telegram_connected is true, mention Telegram. If web_push_available is true, mention browser notifications. If delivery is none, say it is saved in Jarvis and visible in the Web reminders panel. Do not say reminders require Telegram. Do not mention tool names.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            CreateReminderTool::NAME,
            ListRemindersTool::NAME,
            UpdateReminderTool::NAME,
            SnoozeReminderTool::NAME,
            CompleteReminderTool::NAME,
            CancelReminderTool::NAME,
        ];
    }
}
