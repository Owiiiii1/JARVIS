<?php

namespace App\Enums;

enum JarvisNotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Urgent = 'urgent';
}
