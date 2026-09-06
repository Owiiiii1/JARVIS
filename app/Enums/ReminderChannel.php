<?php

namespace App\Enums;

enum ReminderChannel: string
{
    case Telegram = 'telegram';
    case WebPush = 'web_push';
}
