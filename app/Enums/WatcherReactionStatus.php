<?php

namespace App\Enums;

enum WatcherReactionStatus: string
{
    case Pending = 'pending';
    case Skipped = 'skipped';
    case Executed = 'executed';
    case Proposed = 'proposed';
    case Failed = 'failed';
}
