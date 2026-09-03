<?php

namespace App\Services\Telegram\Pairing;

final class TelegramPairingMessages
{
    public const REQUEST_CODE = 'Привет! Для доступа к Jarvis нужен код авторизации. Введите код, полученный от владельца.';

    public const ALREADY_AUTHORIZED = 'Вы уже авторизованы в Jarvis.';

    public const AI_COMING_SOON = 'AI-чат будет подключён на следующем этапе.';

    public const INVALID_CODE = 'Код не найден. Проверьте код или обратитесь к владельцу Jarvis.';

    public const DISABLED_USER = 'Доступ к этому аккаунту отключён. Обратитесь к владельцу Jarvis.';

    public const PAIRING_SUCCESS = 'Авторизация успешна.';

    public const PAIRING_CONNECTED = 'Jarvis подключён к вашему аккаунту.';

    public const USER_ALREADY_HAS_TELEGRAM = 'К этому аккаунту уже подключён Telegram. Обратитесь к владельцу Jarvis для переподключения.';

    public const SEND_CODE_AS_TEXT = 'Пожалуйста, отправьте код авторизации текстовым сообщением.';

    public const UNSUPPORTED_MESSAGE_TYPE = 'Этот тип сообщений пока не поддерживается.';

    public const GROUP_PAIRING_HINT = 'Авторизация выполняется в личном чате с ботом.';
}
