<?php

namespace App\Services\Reminders;

final class ReminderToolPrompt
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'create_reminder creates a one-time Jarvis Core reminder. Call it when the user asks to be reminded and the time is exact (clock time or a relative duration such as "in 2 minutes"). Telegram is optional delivery, not a create requirement.',
            'Only call create_reminder when the current user message is itself a reminder request. Follow-ups such as "ты тут?" are not reminder requests.',
            'If the day is known but the clock time is missing, ask "Во сколько напомнить?" and do not call the tool. Do not invent 09:00 or another default time.',
            'Dayparts such as "tomorrow morning" without a clock time are not exact — ask.',
            'Recurring reminders are not supported yet. If the user asks for a repeating reminder, say so and do not create a one-time reminder as a substitute.',
            'After a successful create_reminder, confirm in natural language using the returned local time. If telegram_connected is true, mention that it will also be sent in Telegram. If telegram_connected is false, say it is saved in Jarvis and visible in the Web reminders panel. Do not say reminders require Telegram. Do not promise Web Push or browser notifications. Do not mention tool names.',
        ];
    }
}
