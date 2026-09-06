<?php

namespace App\Enums;

enum WatcherCreatedBy: string
{
    case User = 'user';
    case Tool = 'tool';
    case Ui = 'ui';
}
