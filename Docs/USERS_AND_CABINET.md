# Users, roles, Cabinet, Telegram pairing

Два уровня на одном Core: **owner** и **user**. Owner — обычная строка `users` с ролью `owner`, не hardcoded `user_id`. Conversation Engine один. Различия — authorization и enabled features.

Связано: [CHANNELS.md](CHANNELS.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [DATABASE.md](DATABASE.md), [INTEGRATIONS.md](INTEGRATIONS.md), ADR-016–031 в [DECISIONS.md](DECISIONS.md).

Фактический код сегодня: любой залогиненный человек видит Admin Panel. Это **баг относительно целевой модели**, не целевое поведение. См. [CURRENT_STATE.md](CURRENT_STATE.md).

---

## Roles

Минимум два значения. Сложный RBAC-пакет не обязателен. Расширяемый enum / колонка `users.role`.

| Role | Кто | Web после login | Telegram | Admin / integrations |
| --- | --- | --- | --- | --- |
| `owner` | один главный владелец инстанса | **Admin Panel** | тот же pairing, код `2000` | `*` |
| `user` | все остальные в каталоге Users | **Personal Cabinet** | pairing своим `access_code` | нет |

Ровно один owner на инстанс (unique constraint или эквивалент). Не ветвить AI Core `if ($userId === 1)`.

### Owner (`*`)

Исключительный доступ ко всему инстансу:

- Admin Panel, Users, User Cards, чужие Chats / Topics;
- AI Settings (platform + чужие user settings);
- Telegram Settings, Telegram Groups, group chats, outbound в группы;
- Integrations (Google Calendar, Gmail, ElevenLabs, позже другие);
- system settings, diagnostics, logs;
- impersonation;
- все будущие tools/actions.

Owner **также** обычный участник Conversation Core: своя history, topics, memories, Telegram DM, User General Prompt.

### User

Пока **только**:

- Web Cabinet: login, Profile, Chat (список, New Chat, свои треды);
- Telegram DM после pairing кодом.

**Не** имеет (backend 403 / deny, не только скрытое меню):

- Admin Panel, Settings, Users, Telegram Groups;
- чужие chats / memory / topics;
- integrations, Gmail, Google Calendar;
- system AI settings, logs, admin APIs.

Все query scoped by `user_id`. ADR-021, ADR-030.

---

## Access code

Не web-пароль. Не email. Не database id. Код **первичной привязки внешнего канала** (сейчас Telegram).

| Правило | Деталь |
| --- | --- |
| Уникальность | DB unique + генерация с retry при коллизии |
| Человекочитаемый | короткий набор символов; точный алфавит `TBD` (цифры допустимы) |
| Owner | зарезервирован **`2000`**. Не переиспользовать. Не считать web password |
| Обычный user | генерируется при создании записи |
| Видимость | owner видит код на User Card; может regenerate |
| После pairing | код для последующих Telegram-сообщений не спрашивается |
| Web Cabinet | вход email/login + password. Access code **не** секрет кабинета |

Неверный / неизвестный код: не создавать User, не вызывать AI.

---

## Web authentication split

Один `User` model, один guard допустим. После login:

```
if role === owner → Admin Panel (dashboard)
if role === user  → Personal Cabinet
```

User на admin route: **403** или redirect в cabinet по согласованной policy. Решение фиксируется в реализации; инвариант — user не видит admin data. Owner на cabinet: свой кабинет или impersonation; не обязательно запрещать owner иметь personal chats UI (`TBD` поверхность: отдельный cabinet vs admin «мои чаты»).

Impersonation: только owner, без пароля жертвы. ADR-020.

---

## Admin: Users — каталог Jarvis

Не «admin accounts». Таблица всех людей инстанса.

Колонки:

- name;
- email / login;
- role (`owner` / `user`);
- access_code;
- status;
- Telegram linked yes/no;
- chats count;
- messages count;
- last activity;
- created_at.

Строка → User Card. Только owner.

---

## User Card (owner only)

- profile, role, status;
- access_code + **Regenerate Code**;
- Telegram linked + **Unlink Telegram** (+ later relink);
- password set / reset (hash only; plaintext никогда);
- Chats (read/debug);
- Topics;
- AI Settings (User General Prompt, override);
- Open Cabinet / impersonate.

Обычный user эту страницу не видит.

---

## Personal Cabinet (`role=user`)

Минимум: **Chat**, **Profile**.

Chat как ChatGPT:

- sidebar списка своих chats;
- New Chat;
- открыть чат;
- history + input.

Каждый chat = `conversations` с этим `user_id`. Long-term memory общая **внутри** user. New Chat ≠ новая память. ADR-017.

---

## Telegram pairing

Факт с аудита: webhook ACK `{ok:true}`, handlers нет, бот не отвечает. Целевая логика ниже. Транспорт: **webhook** + Nutgram. Long polling не нужен.

Адаптер **не** вызывает LLM. Неверный код и `/start` без pairing — системные ответы, не Conversation AI.

### Один Telegram identity

`(channel=telegram, external_user_id)` уникален. Нельзя привязать один Telegram account к двум Jarvis Users одновременно.

Owner может unlink / later relink и regenerate access code.

### `/start` — identity нет

Системный текст (смысл, формулировка `TBD`):

«Привет. Для доступа к Jarvis нужен код авторизации. Введите код, полученный от владельца.»

AI не вызывается. User не создаётся.

### Текст без pairing

Любое подходящее текстовое сообщение = попытка access code.

```
Telegram update
  → identify Telegram user
  → lookup channel_identities
  → absent
  → parse access_code
  → find active User by access_code
```

Код не найден:

«Код не найден. Проверьте код или обратитесь к владельцу Jarvis.»

Не создавать User. Не звать AI. Не писать это в normal conversation history. Auth/security event — можно.

### Код найден — pairing

Создать `channel_identities`:

- channel = `telegram`;
- `external_user_id`;
- username, first/last name если есть;
- `user_id`;
- `linked_at`;
- `last_seen_at`.

Дальше Telegram ID — авторизованный канал этого User. Код больше не спрашивать.

Сразу после pairing:

```
Telegram Adapter
  → Conversation Engine
  → Conversation AI
```

Первое приветствие генерирует **Conversation AI** (platform prompt + User General Prompt + profile + memory, на старте memory может быть пустой). Не заканчивать статическим «Вы авторизованы» как единственным ответом. Короткое системное «привязка успешна» перед AI — допустимо.

### Авторизованный DM

```
Webhook → Nutgram / Adapter → identity → User → Conversation
  → persist inbound → Context Builder → Conversation AI
  → persist outbound → Adapter send
```

Тот же путь для owner и user. Права tools/groups проверяет Core, не адаптер.

### Повторный `/start` при уже связанной identity

Код не спрашивать. Предпочтительно Conversation AI (или короткое системное + AI).

### Owner Telegram

Тот же механизм. Первичный код **`2000`** → связь с `role=owner`. Отдельного owner-бота нет.

---

## Groups и integrations

Telegram Groups в админке — **только owner**. Обычный user не видит группы, не получает group knowledge в personal prompt, не шлёт в группы.

Google Calendar / Gmail / ElevenLabs — **только owner**, через Integration / Tool Layer. Не вшивать в Telegram adapter. [INTEGRATIONS.md](INTEGRATIONS.md).

---

## Memory scopes

- personal memory каждого User (включая owner);
- Telegram group knowledge (owner/analysis);
- system/global при необходимости.

Cross-user retrieval запрещён. Group knowledge не льётся в cabinet обычного user.

---

## Что не делать

- Не называть access_code паролем кабинета.
- Не создавать User из неизвестного Telegram.
- Не пускать неверный код в AI.
- Не давать `role=user` admin routes «потому что залогинен».
- Не hardcode owner по `id=1`.
- Не строить второй Conversation Engine для owner.
