<?php

namespace App\Enums;

enum WatcherReactionType: string
{
    case Notify = 'notify';
    case CreateNotification = 'create_notification';
    case CreateReminder = 'create_reminder';
    case CreateTask = 'create_task';
    case RunInternalAnalysis = 'run_internal_analysis';
    case ProposeAction = 'propose_action';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'alert', 'tell', 'say' => self::Notify,
            'notification', 'inbox' => self::CreateNotification,
            'reminder' => self::CreateReminder,
            'task' => self::CreateTask,
            'analyze', 'analysis', 'read' => self::RunInternalAnalysis,
            'propose', 'confirm', 'external' => self::ProposeAction,
            default => self::tryFrom($raw),
        };
    }
}
