<?php

namespace App\Services\Telegram;

use JsonException;
use SergiX44\Nutgram\Hydrator\Hydrator;
use SergiX44\Nutgram\Telegram\Types\Common\Update;

final class TelegramWebhookProcessor
{
    public function __construct(
        private readonly TelegramBotFactory $botFactory,
    ) {}

    /**
     * @throws JsonException
     */
    public function process(string $payload, string $token): void
    {
        ignore_user_abort(true);
        set_time_limit(120);

        if (trim($payload) === '') {
            return;
        }

        $bot = $this->botFactory->make($token);

        /** @var Update $update */
        $update = $bot->getContainer()
            ->get(Hydrator::class)
            ->hydrate(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), Update::class);

        $bot->processUpdate($update);
    }
}
