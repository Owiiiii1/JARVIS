<?php

namespace App\Enums;

enum SynthesisType: string
{
    case ProjectStatus = 'project_status';
    case PersonStatus = 'person_status';
    case WaitingFor = 'waiting_for';
    case Commitments = 'commitments';
    case Blockers = 'blockers';
    case RecentChanges = 'recent_changes';
    case AttentionNeeded = 'attention_needed';
    case DailyDigest = 'daily_digest';
    case WeeklyDigest = 'weekly_digest';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';
        $raw = str_replace([' ', '-'], '_', $raw);

        return match ($raw) {
            'project', 'status', 'project_status' => self::ProjectStatus,
            'person', 'people', 'person_status' => self::PersonStatus,
            'waiting', 'waiting_for', 'waiting-for' => self::WaitingFor,
            'commitment', 'commitments', 'promises' => self::Commitments,
            'blocker', 'blockers' => self::Blockers,
            'changes', 'recent', 'recent_changes' => self::RecentChanges,
            'attention', 'attention_needed', 'stalled' => self::AttentionNeeded,
            'daily', 'daily_digest', 'today' => self::DailyDigest,
            'weekly', 'weekly_digest', 'week' => self::WeeklyDigest,
            default => self::tryFrom($raw),
        };
    }
}
