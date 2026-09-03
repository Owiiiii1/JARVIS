<?php

namespace App\Services\Telegram;

final class TelegramConversationMessages
{
    public const CONNECTED_WITH_CHAT = 'Jarvis подключён. Текущий чат: «%s».';

    public const SELECT_CHAT = 'Выберите чат:';

    public const NO_CHATS = 'У вас пока нет чатов.';

    public const CHAT_SELECTED = 'Выбран чат «%s».';

    public const ENTER_NEW_CHAT_TITLE = 'Введите название нового чата.';

    public const CHAT_CREATED = 'Создан и выбран чат «%s».';

    public const CANCELLED = 'Отменено.';

    public const CURRENT_CHAT = 'Текущий чат: «%s».';

    public const INVALID_TITLE = 'Название чата должно быть от 1 до 120 символов.';

    public const CHAT_NOT_FOUND = 'Чат не найден.';

    public const MESSAGE_SAVED = 'Сообщение сохранено в чате «%s». AI будет подключён на следующем этапе.';

    public const AI_FAILURE = 'Не удалось получить ответ от AI. Попробуйте ещё раз позже.';

    public const LIST_TRUNCATED = 'Показаны последние 20 чатов.';

    public static function connectedWithChat(string $title): string
    {
        return sprintf(self::CONNECTED_WITH_CHAT, $title);
    }

    public static function chatSelected(string $title): string
    {
        return sprintf(self::CHAT_SELECTED, $title);
    }

    public static function chatCreated(string $title): string
    {
        return sprintf(self::CHAT_CREATED, $title);
    }

    public static function currentChat(string $title): string
    {
        return sprintf(self::CURRENT_CHAT, $title);
    }

    public static function messageSaved(string $title): string
    {
        return sprintf(self::MESSAGE_SAVED, $title);
    }
}
