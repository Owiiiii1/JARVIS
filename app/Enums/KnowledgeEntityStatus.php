<?php

namespace App\Enums;

enum KnowledgeEntityStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case OrphanCandidate = 'orphan_candidate';
}
