<?php

namespace App\Enums;

enum ToolOperationClass: string
{
    case Read = 'read';
    case Write = 'write';
    case Destructive = 'destructive';
}
