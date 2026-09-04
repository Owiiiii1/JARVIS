<?php

namespace App\Enums;

enum ToolExecutionLogStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
    case ConfirmationRequired = 'confirmation_required';
}
