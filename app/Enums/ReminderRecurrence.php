<?php

namespace App\Enums;

enum ReminderRecurrence: string
{
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
