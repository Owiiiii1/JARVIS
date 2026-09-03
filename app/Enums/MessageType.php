<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case System = 'system';
    case Photo = 'photo';
    case Document = 'document';
    case Video = 'video';
    case Voice = 'voice';
    case Audio = 'audio';
    case Sticker = 'sticker';
    case Location = 'location';
    case Contact = 'contact';
    case Poll = 'poll';
    case Unsupported = 'unsupported';
}
