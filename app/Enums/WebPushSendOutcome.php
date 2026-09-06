<?php

namespace App\Enums;

enum WebPushSendOutcome: string
{
    case Sent = 'sent';
    case Gone = 'gone';
    case Transient = 'transient';
    case Permanent = 'permanent';
    case Skipped = 'skipped';
}
