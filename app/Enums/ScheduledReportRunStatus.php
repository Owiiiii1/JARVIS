<?php

namespace App\Enums;

enum ScheduledReportRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
