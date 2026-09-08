<?php

namespace App\Enums;

enum ScheduledReportStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
}
