<?php

namespace App\Services\Telegram;

use App\Services\Telegram\Handlers\TelegramHandlerRegistrar;
use SergiX44\Nutgram\Nutgram;

final class TelegramBotFactory
{
    public function __construct(
        private readonly TelegramHandlerRegistrar $handlerRegistrar,
    ) {}

    public function make(string $token): Nutgram
    {
        $bot = new Nutgram($token);
        $this->handlerRegistrar->register($bot);

        return $bot;
    }
}
