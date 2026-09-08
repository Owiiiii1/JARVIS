<?php

namespace App\Enums;

enum ScheduledReportPeriodMode: string
{
    case Today = 'today';
    case Tomorrow = 'tomorrow';
    case SincePreviousReport = 'since_previous_report';
    case Last24h = 'last_24h';
}
