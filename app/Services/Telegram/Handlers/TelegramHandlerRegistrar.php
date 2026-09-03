<?php

namespace App\Services\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;
use Throwable;

final class TelegramHandlerRegistrar
{
    public function __construct(
        private readonly TelegramUpdateHandler $updateHandler,
    ) {}

    public function register(Nutgram $bot): void
    {
        $bot->onMessage(fn (Nutgram $bot) => $this->updateHandler->handleMessage($bot));
        $bot->onCallbackQuery(fn (Nutgram $bot) => $this->updateHandler->handleCallbackQuery($bot));

        $bot->onException(function (Nutgram $bot, Throwable $e): void {
            report($e);
        });
    }
}
