<?php

namespace App\Services\Telegram;

enum TelegramVoiceInboundStatus: string
{
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Turn = 'turn';
    case UserNotice = 'user_notice';
}
