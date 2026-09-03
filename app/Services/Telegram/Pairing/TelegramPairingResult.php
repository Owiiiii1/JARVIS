<?php

namespace App\Services\Telegram\Pairing;

use App\Models\ChannelIdentity;

final class TelegramPairingResult
{
    /**
     * @param  list<string>  $messages
     */
    public function __construct(
        public readonly TelegramPairingOutcome $outcome,
        public readonly array $messages = [],
        public readonly ?ChannelIdentity $identity = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function successMessages(): array
    {
        return [
            TelegramPairingMessages::PAIRING_SUCCESS,
            TelegramPairingMessages::PAIRING_CONNECTED,
        ];
    }
}
