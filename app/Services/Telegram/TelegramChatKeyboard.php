<?php

namespace App\Services\Telegram;

use App\Models\Conversation;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;

final class TelegramChatKeyboard
{
    public const BUTTON_CHATS = 'Чаты';

    public const BUTTON_NEW_CHAT = 'Новый чат';

    public const BUTTON_CURRENT_CHAT = 'Текущий чат';

    public const BUTTON_CANCEL = 'Отмена';

    public const CALLBACK_SELECT_PREFIX = 'c:';

    /**
     * @var list<string>
     */
    public const MENU_BUTTONS = [
        self::BUTTON_CHATS,
        self::BUTTON_NEW_CHAT,
        self::BUTTON_CURRENT_CHAT,
        self::BUTTON_CANCEL,
    ];

    public function menu(bool $awaitingTitle = false): ReplyKeyboardMarkup
    {
        $markup = ReplyKeyboardMarkup::make(resize_keyboard: true, is_persistent: true)
            ->addRow(
                KeyboardButton::make(self::BUTTON_CHATS),
                KeyboardButton::make(self::BUTTON_NEW_CHAT),
                KeyboardButton::make(self::BUTTON_CURRENT_CHAT),
            );

        if ($awaitingTitle) {
            $markup->addRow(KeyboardButton::make(self::BUTTON_CANCEL));
        }

        return $markup;
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     */
    public function chatList(Collection $conversations): InlineKeyboardMarkup
    {
        $markup = InlineKeyboardMarkup::make();

        foreach ($conversations as $conversation) {
            $markup->addRow(
                InlineKeyboardButton::make(
                    text: $conversation->title,
                    callback_data: self::CALLBACK_SELECT_PREFIX.$conversation->id,
                )
            );
        }

        return $markup;
    }

    public function parseSelectCallback(?string $data): ?int
    {
        if ($data === null || ! str_starts_with($data, self::CALLBACK_SELECT_PREFIX)) {
            return null;
        }

        $id = substr($data, strlen(self::CALLBACK_SELECT_PREFIX));

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return (int) $id;
    }
}
