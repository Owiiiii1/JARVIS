<?php

namespace App\Enums;

enum WatcherTriggerType: string
{
    case KnowledgeEvent = 'knowledge_event';
    case TaskState = 'task_state';
    case ReminderState = 'reminder_state';
    case TimeCondition = 'time_condition';
    case CalendarEvent = 'calendar_event';
    case GmailMessage = 'gmail_message';
    case GithubEvent = 'github_event';

    public function isExternal(): bool
    {
        return in_array($this, [self::CalendarEvent, self::GmailMessage, self::GithubEvent], true);
    }

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'knowledge', 'entity_event', 'timeline' => self::KnowledgeEvent,
            'task', 'tasks' => self::TaskState,
            'reminder', 'reminders' => self::ReminderState,
            'time', 'deadline', 'schedule' => self::TimeCondition,
            'calendar', 'google_calendar' => self::CalendarEvent,
            'gmail', 'email', 'mail' => self::GmailMessage,
            'github', 'git', 'commit' => self::GithubEvent,
            default => self::tryFrom($raw),
        };
    }
}
