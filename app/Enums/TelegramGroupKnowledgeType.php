<?php

namespace App\Enums;

enum TelegramGroupKnowledgeType: string
{
    case Summary = 'summary';
    case Decision = 'decision';
    case Task = 'task';
    case EventFact = 'event_fact';
}
