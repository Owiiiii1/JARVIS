<?php

namespace App\Services\Telegram;

use App\Models\ChannelIdentity;
use App\Services\Telegram\Contracts\TelegramDmOutbound;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

final class TelegramNutgramDmOutbound implements TelegramDmOutbound
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly ChannelIdentity $identity,
        private readonly TelegramBotManager $telegram,
    ) {}

    public function sendText(
        string $text,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
    ): void {
        $this->bot->sendMessage(
            text: $text,
            chat_id: $this->identity->external_chat_id,
            reply_markup: $replyMarkup,
        );
    }

    public function sendVoice(
        string $absolutePath,
        string $filename,
        InlineKeyboardMarkup|ReplyKeyboardMarkup|null $replyMarkup = null,
    ): void {
        unset($replyMarkup);

        $mime = match (true) {
            str_ends_with(strtolower($filename), '.ogg') => 'audio/ogg',
            str_ends_with(strtolower($filename), '.m4a') => 'audio/mp4',
            default => 'audio/mpeg',
        };

        $this->telegram->sendVoiceMessage(
            chatId: (string) $this->identity->external_chat_id,
            absolutePath: $absolutePath,
            filename: $filename,
            mime: $mime,
        );
    }
}
