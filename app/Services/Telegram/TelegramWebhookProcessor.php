<?php

namespace App\Services\Telegram;

use JsonException;
use SergiX44\Nutgram\Hydrator\Hydrator;
use SergiX44\Nutgram\Telegram\Types\Common\Update;
use Throwable;

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
        if (trim($payload) === '') {
            return;
        }

        $bot = $this->botFactory->make($token);

        try {
            /** @var Update $update */
            $update = $bot->getContainer()
                ->get(Hydrator::class)
                ->hydrate(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), Update::class);

            $bot->processUpdate($update);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
