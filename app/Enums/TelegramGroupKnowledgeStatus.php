<?php

namespace App\Enums;

enum TelegramGroupKnowledgeStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Obsolete = 'obsolete';
    case Disputed = 'disputed';
}
