<?php

namespace App\Enums;

enum IntegrationAccountStatus: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Error = 'error';
    case Revoked = 'revoked';
}
