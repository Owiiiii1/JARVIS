# API

API нужен Web Cabinet, Mobile, Desktop и интеграциям. Telegram в Phase 1 может ходить в Core in-process. Cabinet использует тот же контракт, что Phase 3 клиенты (поверхность раньше публичного mobile API).

Окончательные URL и форматы payload **не фиксируются**. Ниже — логические группы и инварианты.

---

## Инварианты

- Все клиентские операции идут в Jarvis Core, не в «mobile backend».
- Личная история и personal memory — только **текущего** `user_id`. Те же данные, что у его Telegram DM / Cabinet.
- Ownership: id в URL недостаточен. Policy проверяет, что conversation/message/topic принадлежит user. ADR-021.
- Клиент не присылает platform prompt и не выбирает произвольный vendor. User General Prompt и override задаются в админке / user settings, ядро резолвит само.
- Group administration — не cabinet API. `TBD` узкий read для owner.
- Два auth context: admin vs cabinet. Обычный user не получает admin endpoints.
- Схема токенов (sanctum, JWT, cookies) — `TBD`.
- Realtime и voice — отдельные группы.

---

## Логические группы

### Authentication

- вход / выход / refresh кабинета (не admin login);
- привязка device session;
- кто я (`user` + доступные features/channels).

Точные flows — `TBD`. Impersonation не является обычным login API.

### Conversations

- список **своих** разговоров;
- создать новый (пустой raw, та же user memory);
- открыть / переименовать;
- метаданные (заголовок, последняя активность, статус);
- архив/удаление — later (`TBD`).

Не отдавать derived memory целиком в списке. Не отдавать чужие conversations.

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
- пользовательские предпочтения профиля — узкий subset, не вся админка;
- свой General Prompt — только если продуктово разрешено править из кабинета (`TBD`; админ правит всегда с User Card).

Админские CRUD провайдеров, Users, impersonation — admin surface, не cabinet/mobile API.

---

## Ошибки и идемпотентность

Клиент может повторить send с idempotency key (`TBD`). Ядро уже планирует идемпотентность по channel_message_id; для API нужен свой ключ.

---

## Версионирование

`TBD` (`/api/v1` или header). Не выбирать сейчас.

---

## Связь с Phase 1

Telegram-срез Phase 1 может обойтись без public HTTP. Cabinet и Phase 3 бьют в тот же engine и те же ownership-правила, чтобы не переписывать оркестрацию.
