<?php

namespace App\Enums;

enum ConversationKind: string
{
    case Personal = 'personal';

    case Group = 'group';
}
