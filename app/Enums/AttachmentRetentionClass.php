<?php

namespace App\Enums;

enum AttachmentRetentionClass: string
{
    case Ephemeral = 'ephemeral';
    case Persistent = 'persistent';
}
