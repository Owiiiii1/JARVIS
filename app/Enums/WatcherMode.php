<?php

namespace App\Enums;

enum WatcherMode: string
{
    case OneShot = 'one_shot';
    case Recurring = 'recurring';
}
