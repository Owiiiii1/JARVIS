<?php

namespace App\Enums;

enum ToolMutationKind: string
{
    case Read = 'read';
    case WriteInternal = 'write_internal';
    case WriteExternal = 'write_external';
    case Destructive = 'destructive';

    public function isReadOnly(): bool
    {
        return $this === self::Read;
    }

    public function isMutation(): bool
    {
        return $this !== self::Read;
    }
}
