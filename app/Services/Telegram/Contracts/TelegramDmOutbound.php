<?php

namespace App\Services\Telegram\Contracts;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

interface TelegramDmOutbound
{
    public function sendText(
        string $text,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
    ): void;

    public function sendVoice(
        string $absolutePath,
        string $filename,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
    ): void;
}
