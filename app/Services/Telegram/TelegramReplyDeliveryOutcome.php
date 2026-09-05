<?php

namespace App\Services\Telegram;

enum TelegramReplyDeliveryOutcome: string
{
    case Text = 'text';
    case Voice = 'voice';
    case VoiceFallbackText = 'voice_fallback_text';
}
