<?php

namespace App\Services\Reminders;

use App\Services\Reminders\Contracts\SendsReminderTelegram;
use App\Services\Telegram\TelegramBotManager;

final class TelegramReminderSender implements SendsReminderTelegram
{
    public function __construct(
        private readonly TelegramBotManager $telegram,
    ) {}

    public function send(string $chatId, string $text): void
    {
        $this->telegram->sendTextMessage($chatId, $text);
    }
}
