<?php

namespace App\Enums;

enum MemoryKind: string
{
    case Fact = 'fact';
    case Preference = 'preference';
    case Instruction = 'instruction';
    case Relationship = 'relationship';
    case ProjectContext = 'project_context';
    case Other = 'other';
}
