<?php

namespace App\Enums;

enum ProactiveSuggestionType: string
{
    case FollowUp = 'follow_up';
    case ProjectBlocked = 'project_blocked';
    case WaitingTooLong = 'waiting_too_long';
    case DeadlineRisk = 'deadline_risk';
    case StaleProject = 'stale_project';
    case CommitmentDue = 'commitment_due';
}
