<?php

namespace App\Enums;

enum ToolConfirmationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Executed = 'executed';
}
