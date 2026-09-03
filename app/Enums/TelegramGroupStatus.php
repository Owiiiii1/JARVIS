<?php

namespace App\Enums;

enum TelegramGroupStatus: string
{
    case Connected = 'connected';
    case Restricted = 'restricted';
    case Left = 'left';
}
