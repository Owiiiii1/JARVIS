<?php

namespace App\Enums;

enum ToolConfirmationDecision: string
{
    case Allowed = 'allowed';
    case ConfirmationRequired = 'confirmation_required';
    case Denied = 'denied';
}
