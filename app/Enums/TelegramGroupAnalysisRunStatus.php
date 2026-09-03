<?php

namespace App\Enums;

enum TelegramGroupAnalysisRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
