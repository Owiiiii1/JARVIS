<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
