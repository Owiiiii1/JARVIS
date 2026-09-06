<?php

namespace App\Enums;

enum KnowledgeEventType: string
{
    case TaskCreated = 'task_created';
    case TaskCompleted = 'task_completed';
    case ReminderCreated = 'reminder_created';
    case ProjectCreated = 'project_created';
    case ProjectArchived = 'project_archived';
    case ConversationMentioned = 'conversation_mentioned';
    case EmailReceived = 'email_received';
    case CalendarEvent = 'calendar_event';
    case GithubCommitSeen = 'github_commit_seen';
    case FileUploaded = 'file_uploaded';
    case GroupEvent = 'group_event';
    case ManualNote = 'manual_note';
    case KnowledgeLinked = 'knowledge_linked';
    case RelationshipSuperseded = 'relationship_superseded';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'github_commit', 'commit_seen' => self::GithubCommitSeen,
            'note', 'manual' => self::ManualNote,
            default => self::tryFrom($raw),
        };
    }
}
