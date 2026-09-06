<?php

namespace App\Enums;

enum WatcherOccurrenceStatus: string
{
    case Matched = 'matched';
    case Suppressed = 'suppressed';
    case Executed = 'executed';
    case Failed = 'failed';
    case Aggregated = 'aggregated';
}
