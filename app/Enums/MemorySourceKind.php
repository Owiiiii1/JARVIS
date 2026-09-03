<?php

namespace App\Enums;

enum MemorySourceKind: string
{
    case DirectConversation = 'direct_conversation';
    case Summary = 'summary';
    case ManualAdmin = 'manual_admin';
    case System = 'system';
}
