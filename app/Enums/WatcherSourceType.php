<?php

namespace App\Enums;

enum WatcherSourceType: string
{
    case KnowledgeEntity = 'knowledge_entity';
    case Project = 'project';
    case Task = 'task';
    case Reminder = 'reminder';
    case Gmail = 'gmail';
    case Calendar = 'calendar';
    case Github = 'github';
    case Time = 'time';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'entity', 'knowledge' => self::KnowledgeEntity,
            'email', 'mail' => self::Gmail,
            'google_calendar' => self::Calendar,
            'git', 'repository', 'repo' => self::Github,
            'clock', 'deadline' => self::Time,
            default => self::tryFrom($raw),
        };
    }
}
