<?php

namespace App\Enums;

enum ConversationSummaryStatus: string
{
    case Current = 'current';
    case Superseded = 'superseded';
}
