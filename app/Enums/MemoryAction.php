<?php

namespace App\Enums;

enum MemoryAction: string
{
    case Create = 'create';
    case Reinforce = 'reinforce';
    case Supersede = 'supersede';
    case Dispute = 'dispute';
    case Ignore = 'ignore';
}
