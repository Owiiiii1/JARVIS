# API

API нужен User Workspace, Owner Workspace, Desktop, Mobile. Telegram ходит в Core in-process. Workspace может оставаться same-origin session; Desktop/Mobile требуют versioned Client API.

Целевой контракт: [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md). Этот файл — инварианты. Окончательные URL **не фиксируются**. Клиенты не реализуют Memory/Tools/credentials локально.

Окончательные URL и форматы payload **не фиксируются**. Ниже — логические группы и инварианты.

---

## Инварианты

- Все клиентские операции идут в Jarvis Core, не в «mobile backend».
- Личная история и personal memory — только **текущего** `user_id`. Те же данные, что у его Telegram DM / Cabinet.
- Ownership: id в URL недостаточен. Policy проверяет, что conversation/message/topic принадлежит user. ADR-021.
- Клиент не присылает platform prompt и не выбирает произвольный vendor. Ядро резолвит Owner Conversation AI или Default User Conversation AI. User General Prompt правит сам user в Personal Workspace.
- GitHub/Google credentials never leave Core. Clients send conversation turns; Core may call GitHub tools.
- Group administration — не user workspace API. `TBD` узкий read для owner.
- Два web context: owner → `/jarvis` + admin; `role=user` → `/chat`. User на admin API/routes — deny. `/cabinet` is compatibility only.
- Access code не является API/web password.
- Схема токенов (sanctum, JWT, cookies) — `TBD`.
- Realtime и voice — отдельные группы.

---

## Логические группы

### Authentication

- вход / выход / refresh: owner и user разные landing;
- Telegram pairing не через этот API (webhook);
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
- свой General Prompt — user правит в Cabinet; owner видит/может править с User Card;
- timezone (IANA) своего профиля;
- reminders не через отдельный push API: создание в чате, доставка только Telegram.

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
