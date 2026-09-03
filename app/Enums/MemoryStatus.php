<?php

namespace App\Enums;

enum MemoryStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Disputed = 'disputed';
    case Obsolete = 'obsolete';
}
