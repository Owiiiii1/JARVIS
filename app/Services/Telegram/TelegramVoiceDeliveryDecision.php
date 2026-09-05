<?php

namespace App\Services\Telegram;

use App\Enums\TelegramResponseMode;

final class TelegramVoiceDeliveryDecision
{
    public static function shouldAttemptVoice(
        TelegramResponseMode $mode,
        string $inboundModality,
        bool $forceText = false,
    ): bool {
        if ($forceText) {
            return false;
        }

        return match ($mode) {
            TelegramResponseMode::Text => false,
            TelegramResponseMode::Voice => true,
            TelegramResponseMode::Auto => $inboundModality === 'voice',
        };
    }
}
