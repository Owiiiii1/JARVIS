<?php

namespace App\Enums;

enum ReferenceOutcome: string
{
    case None = 'none';
    case Resolved = 'resolved';
    case Ambiguous = 'ambiguous';
    case IncompleteResolved = 'incomplete_resolved';
    case Unresolved = 'unresolved';
}
