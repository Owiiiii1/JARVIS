<?php

namespace App\Enums;

enum AttachmentSummaryStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case NotRequired = 'not_required';
}
