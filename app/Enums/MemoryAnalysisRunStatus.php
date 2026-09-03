<?php

namespace App\Enums;

enum MemoryAnalysisRunStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
