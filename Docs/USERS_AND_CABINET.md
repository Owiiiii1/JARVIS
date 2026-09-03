# Users, spaces, Cabinet, Telegram pairing

Два **логических пространства** на общих engines. Role задаёт default **capability set**, не второй Conversation Engine.

- **Owner Space** — полностью изолированный AI-контекст владельца + admin/integrations/groups/projects.
- **User Space** — полностью независимое пространство каждого `role=user`.

User A, User B и Owner personal context **никогда** не смешиваются. Изоляция: `user_id` / scope / configuration domain / capabilities.

Связано: [CHANNELS.md](CHANNELS.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [REMINDERS.md](REMINDERS.md), [PROJECTS.md](PROJECTS.md), [INTEGRATIONS.md](INTEGRATIONS.md), ADR-016–045.

Фактический код (M4): user на admin route получает 403; `/cabinet` и `/cabinet/ai-settings` доступны активному user. Owner General Prompt — Admin Profile. User General Prompt — Cabinet AI Settings. System AI configs — только owner Settings → AI.

---

## Roles

Минимум два значения. Сложный RBAC-пакет не обязателен. Расширяемый enum / колонка `users.role`.

| Role | Кто | Web после login | Telegram | Admin / integrations |
| --- | --- | --- | --- | --- |
| `owner` | один главный владелец инстанса | **Admin Panel** | тот же pairing, код `2000` | `*` |
| `user` | все остальные в каталоге Users | **Personal Cabinet** | pairing своим `access_code` | нет |

Ровно один owner на инстанс. Не `if ($userId === 1)`.

## Spaces

### Owner Space

conversations; conversation summaries; personal memories; **projects**; topics; General Prompt; **Owner Conversation AI** + **Owner Analysis AI**; reminders; Telegram DM; Telegram Groups + group knowledge; integrations; Gmail; Calendar; voice later; tools; proactive later.

### User Space

conversations; summaries; personal memory; General Prompt; **Default User Conversation AI**; Telegram DM; Web Cabinet; reminders; timezone.

Нет: Admin, Groups, Projects, Google, owner AI config.

Engines общие: Conversation, Context Builder, Telegram Adapter, Reminder Engine, AI Provider Layer.

---

## Capabilities

Не размазывать `if role === owner` по всему коду. Role → default set. Проверка в Core.

| Capability | owner | user (сейчас) |
| --- | --- | --- |
| chat | да | да |
| memory | да | да |
| telegram_dm | да | да |
| reminders | да | да |
| cabinet / profile | да | да |
| projects | да | нет |
| telegram_groups | да | нет |
| group_analysis | да | нет |
| gmail | да | нет |
| google_calendar | да | нет |
| integrations_admin | да | нет |
| users_admin | да | нет |
| voice | later | later / нет |
| impersonation | да | нет |

Owner = все. Расширение permissions без нового engine.

---

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

- Web Cabinet: login, Profile (timezone), Chat (список, New Chat, свои треды), свой General Prompt;
- Telegram DM после pairing кодом + Chat Selector;
- reminders (доставка только Telegram).

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
- AI Settings (User General Prompt; optional future override поверх Default User Conversation AI);
- Open Cabinet / impersonate.

Обычный user эту страницу не видит.

---

## Personal Cabinet (`role=user`)

Минимум: **Chat**, **Profile**, плюс редактирование **своего General Prompt**. Timezone в profile.

Chat как ChatGPT: список, New Chat, history, input. Тот же каталог conversations, что в Telegram.

New Chat: пустой raw. Другие чаты — **summaries** в context, не их raw. Targeted raw-on-demand. ADR-036.

AI: **Default User Conversation AI**, не Owner Conversation AI.

---

## Telegram pairing

Факт с аудита: webhook ACK `{ok:true}`, handlers нет, бот не отвечает. Целевая логика ниже. Транспорт: **webhook** + Nutgram. Long polling не нужен.

Адаптер **не** вызывает LLM. Неверный код и `/start` без pairing — системные ответы, не Conversation AI.

### Один Telegram identity

`(channel=telegram, external_user_id)` уникален. Нельзя привязать один Telegram account к двум Jarvis Users одновременно.

На MVP также действует обратное ограничение: **один Jarvis User — одна Telegram identity**. Второй Telegram account к тому же User не привязывается; переподключение только через owner unlink. ADR-046.

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
- `last_seen_at`;
- `active_conversation_id` (тот же `user_id`).

Дальше Telegram ID — авторизованный канал. Код не спрашивать.

**Первый pairing UX:** создать conversation **`Основной`**, сделать active, записать AI greeting туда. Каталог тот же, что Cabinet.

Приветствие — Conversation AI **пространства** (Owner vs Default User config). Не только «Вы авторизованы».

### Telegram Chat Selector

Telegram ≠ один вечный conversation. Меню / commands / buttons **«Чаты»**:

- список conversations этого user;
- выбрать существующий;
- создать новый;
- показать текущий.

После выбора: `Выбран чат «<name>».` Дальнейшие DM → `channel_identities.active_conversation_id`.

New Chat из Telegram = обычная `conversation`, видна в Cabinet.

Active conversation **обязана** принадлежать тому же `user_id`. Хранение: **`channel_identities.active_conversation_id`** (один Telegram identity = одно active). Чище, чем отдельная session, пока один бот-чат на identity.

### Авторизованный DM

```
Webhook → Adapter → identity → User → active conversation
  → persist → Context Builder (space AI) → persist → send
```

Tools/groups — capabilities, не адаптер.

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
- Не резолвить Owner Conversation AI для `role=user`.
- Не считать Telegram одним вечным чатом.
