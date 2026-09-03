<?php

namespace App\Services\Telegram\Pairing;

enum TelegramPairingOutcome: string
{
    case AlreadyLinked = 'already_linked';
    case InvalidCode = 'invalid_code';
    case DisabledUser = 'disabled_user';
    case UserAlreadyHasTelegram = 'user_already_has_telegram';
    case Paired = 'paired';
}
