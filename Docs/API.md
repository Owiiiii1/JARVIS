# API

API нужен Mobile, Desktop и возможным интеграциям. Telegram в Phase 1 может ходить в Core in-process (тот же backend), не через публичный HTTP. Публичный API становится обязательным к Phase 3.

Окончательные URL и форматы payload **не фиксируются**. Ниже — логические группы и инварианты.

---

## Инварианты

- Все клиентские операции идут в Jarvis Core, не в «mobile backend».
- Личная история и personal memory те же, что у Telegram **DM**.
- Клиент не присылает prompt и не выбирает произвольный provider/роль (админка: Conversation AI / Analysis AI).
- Group chat UI — админка; публичный API групп в Phase 3 не обязателен (`TBD`).
- Аутентификация обязательна для mobile/desktop. Схема (sanctum, JWT, cookies) — `TBD`.
- Realtime и voice — отдельные группы, не обязаны существовать в Phase 1.

---

## Логические группы

### Authentication

- вход / выход / refresh;
- привязка device session;
- кто я (`user` + доступные channels).

Точные flows — `TBD`.

### Conversations

- список разговоров пользователя;
- создать / выбрать active;
- метаданные (заголовок, последняя активность).

Не отдавать derived memory целиком в списке.

### Messages

- история страницы/курсора;
- отправить текстовое сообщение → тот же Conversation Engine;
- статус доставки/генерации (`TBD`).

Отправка сообщения — команда ядру, не «сохрани у себя и подумай локально».

### Realtime / streaming

- поток токенов ответа;
- события typing / speaking / interrupted.

Транспорт (SSE, WebSocket) — `TBD`.

### Voice sessions

- открыть/закрыть сессию на conversation;
- слать аудио / получать аудио;
- barge-in сигналы.

Не отдельный AI namespace. См. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

### Settings / status

- публичный статус: модель подключена, бот подключен, «Jarvis доступен»;
- **не** выставлять наружу секреты провайдеров и bot token;
- пользовательские предпочтения профиля — узкий subset, не вся админка.

Админские CRUD провайдеров остаются в web/admin, не обязательно в mobile API.

---

## Ошибки и идемпотентность

Клиент может повторить send с idempotency key (`TBD`). Ядро уже планирует идемпотентность по channel_message_id; для API нужен свой ключ.

---

## Версионирование

`TBD` (`/api/v1` или header). Не выбирать сейчас.

---

## Связь с Phase 1

Если Phase 1 живёт только Telegram webhook → Core, отдельный public API можно не открывать. Контракт engine всё равно тот же, чтобы Phase 3 не переписывала оркестрацию.
