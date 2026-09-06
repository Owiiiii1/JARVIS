<?php

namespace App\Enums;

enum WatcherHealth: string
{
    case Healthy = 'healthy';
    case Waiting = 'waiting';
    case Blocked = 'blocked';
    case Paused = 'paused';
    case Failed = 'failed';
}
