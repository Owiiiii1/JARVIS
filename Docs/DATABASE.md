# Концептуальная модель данных

Это **не** финальная schema и **не** задание на migrations. Имена могут измениться. Группы сущностей фиксируют границы, чтобы Phase 1 storage пережил Phase 2. Telegram Groups: [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md). Users / Cabinet: [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

Source of truth — relational database (сейчас MySQL в окружении проекта). Vector DB не обязательна.

---

## Identity

### users

Account entity. Ядро работает с `user_id`, не с Telegram id. Ровно один `role=owner` на инстанс (не hardcoded id). Остальные — `role=user`.

- email / login;
- password hash (web cabinet / admin) — **не** access_code;
- `role`: `owner` | `user`;
- `access_code` unique (owner зарезервирован **`2000`**);
- `status`;
- `timezone` IANA (например `Europe/Rome`);
- last activity (`TBD`);
- timestamps.
- capabilities: default из role; persisted overrides optional later.

Пароль и access_code — разные поля. Access code виден owner на User Card; не секрет web-login. Plaintext password не хранить.

### user_profiles

Опциональное отделение расширенных profile data от account:

- display name;
- предпочтения;
- возрастные/прочие флаги (`TBD`).

Один профиль на user.

### user_ai_settings

Implemented (M4): unique `user_id`, `general_prompt` nullable, `overrides` json nullable (unused). Owner edits from Profile; user from Cabinet → AI Settings. Self-only.

Platform configs live in **`ai_role_settings`** (not `is_active`):

| role_key | Purpose |
| --- | --- |
| `owner_conversation` | Owner Space personal DM |
| `owner_analysis` | Background jobs: personal memory extraction (M12) and Telegram group analysis (M14); not used in DM |
| `user_conversation` | All User Spaces |

Fields: provider, model, system_prompt, parameters json, is_enabled. Credentials remain in `ai_provider_settings`.

### channel_identities

Связь «внешний аккаунт канала ↔ user».

- `user_id`
- `channel` (`telegram` / …)
- `external_id` (Telegram user id)
- username, first/last name
- `linked_at`, `last_seen_at`
- `active_conversation_id` nullable FK → conversations того же `user_id`

Unique `(channel, external_id)`. Создаётся только после верного access_code.

---

## Communication

### conversations

Логический тред. Не обязан 1:1 совпадать с Telegram chat навсегда.

- `user_id` — **обязателен** для personal (`kind=personal`); владелец чата
- `kind`: `personal` | `group`
- `title`
- `status` (active / archived / …)
- `last_activity_at`
- для `group`: связь с `telegram_groups` (не путать sender сообщений с `user_id` бота/owner)
- указатель на active topic (`TBD`, скорее Phase 2)

Много `personal` на user. Telegram и Cabinet — один каталог (`conversations` + `messages`). Telegram пишет в `active_conversation_id`. New Chat — новая строка, не новый memory store.

### conversation_summaries

Сжатие **одного** conversation (или диапазона его messages). Owner = тот же `user_id`. Нужны для summary-first cross-chat. Не заменяют raw.

**Почему общая таблица, а не отдельный message engine.** Group и personal history — разные *контекстные области* (ADR-012), но один pipeline persist / pagination / attachments / идемпотентности. Второй набор таблиц «group_messages» дублировал бы engine без выигрыша. Различие — `conversations.kind` + `telegram_groups` + поля sender на сообщении. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

### messages

Raw history. Закладывается в Phase 1 и **не ломается** в Phase 2. Одна таблица для direct и group.

- `conversation_id`
- `user_id` — владелец personal conversation; для group может быть null / bot account, не путать с sender
- `role` (user / assistant / system / group_member — точный enum `TBD`)
- `channel` (telegram / mobile / desktop)
- `modality` / `message_type` (text / voice / photo / document / video / …)
- `body` (текст после STT тоже сюда)
- `channel_message_id` / Telegram message ID (идемпотентность)
- `parent_message_id` (M4) — assistant/system reply linked to inbound user message
- sender: Telegram user ID, display name, username (критично для групп)
- reply-to / thread / forum topic id
- attachments metadata
- edited flag (+ optional previous text `TBD`)
- raw channel metadata (JSON) при необходимости
- timestamps
- флаги: interrupted, discarded generation (`TBD`)

Никогда не удаляются автоматически из-за summary/memory. Group raw — до и после анализа. ADR-014.

### channels (справочник или enum)

Тип канала как сущность или enum в identities/messages. Отдельная таблица нужна только если появятся per-channel настройки уровня «канал включён» сверх telegram settings. `TBD`.

---

## Memory (в основном Phase 2)

### topics

Устойчивые темы с owner/scope. Не смешивать автоматически.

Концептуально: `owner_type` + `owner_id` (или эквивалент):

- user topic → `owner_type=user`, `owner_id=user_id`;
- group topic → группа;
- global topic → instance / system.

Имя, статус, timestamps. Retrieval всегда с owner filter.

### message_topic_relations

Many-to-many: message ↔ topic, confidence, classifier version. Нужны, чтобы пересчитать классификацию, не трогая messages.

### memories

Долговременные факты/заметки. Не одна куча «всё, что Jarvis знает».

- `scope`: `personal` | `group_knowledge` | `global_system`
- для `personal` — обязательный `user_id` (owner памяти, не обязательно автор исходной реплики в группе)
- текст/структура факта (`TBD` свободный текст vs typed)
- confidence, status (active / superseded / obsolete / disputed)
- valid_from / valid_until
- `source_kind`: `direct_conversation` | `telegram_group` | `summary` | `manual_admin` | иное
- `source_group_id` — если извлечено из группы
- provenance на message ids

Group knowledge **не** пишется в personal scope автоматически. ADR-012.

### memory_topics

Many-to-many memories ↔ topics.

### memory_sources (или поля на memories)

Ссылки на `message_id` (и при необходимости group id), из которых извлекли факт. Обязательный принцип трассировки.

### memory_revisions

История изменения факта: previous value, new value, reason, actor (system/model version).

### entities / entity_relations

Люди, проекты, места и связи между ними. Можно начать позже Phase 2; схему не запрещать. Graph DB не требуется — достаточно таблиц.

### summaries

Сжатия диапазона messages или темы. Ссылки на `from_message_id` / `to_message_id`. Пересобираемые.

### user_profile

См. Identity выше. Стабильные предпочтения, язык, ограничения. Один на `user_id`. Версионирование — `TBD`.

### M12 implemented tables

Production MySQL (`2026_09_03_220000_create_memory_engine_tables`):

- `conversation_summaries` — `user_id`, `conversation_id`, `summary`, `from_message_id`/`to_message_id`, `message_count`, `version`, `status` (`current`/`superseded`), provider/model, `generated_at`, `metadata`.
- `topics` — personal only: `user_id` + unique `(user_id, normalized_name)`.
- `message_topic_relations` — unique `(message_id, topic_id)`, confidence, source.
- `memories` — M12 `scope=personal` + required `user_id`; kind `fact|preference|instruction|relationship|project_context|other`; status `active|superseded|disputed|obsolete`; confidence; `valid_from`/`valid_until`; `normalized_key`.
- `memory_sources` — provenance (`message_id` / `conversation_id` / `summary_id`, `source_kind`). Long-term fact without provenance is rejected.
- `memory_revisions` — trail; old rows are not deleted on supersede.
- `user_profiles` — compact `summary` per `user_id`, `updated_from_memory_at`.
- `memory_analysis_runs` — job idempotency per conversation/type/message range.

`memory_topics` and entities — not in M12 runtime. **Group knowledge is not stored in `memories`.** M14 uses dedicated tables (below).

Indexes: `user_id`, `conversation_id`, memory `(user_id, status, confidence)`, topic `normalized_name`, summary status/version.

---

## AI

Конфигурация **трёх domains**, не «одна глобальная модель Jarvis». ADR-013, ADR-034.

### ai_providers / ai_provider_settings

Учётные данные и статус провайдеров (в проекте уже есть заготовка таблицы настроек провайдеров в админке). Ядро читает через abstraction, не через UI. Один credential может использоваться несколькими ролями.

### ai_roles / model role mapping

Логические роли — first-class:

| Config | Обязательность | Назначение |
| --- | --- | --- |
| Owner Conversation AI | M4 | только Owner Space |
| Owner Analysis AI | M4 конфиг; M12 jobs | personal memory extract/summaries; группы later |
| Default User Conversation AI | M4 | все User Spaces; не наследует owner |

Позже без смены business logic можно добавить: `classification`, `summarization`, `embeddings`, `memory_extraction`, `voice_reasoning`.

Каждая роль: provider, model, credentials reference, prompt, parameters (temperature и др.). Роли **не обязаны** совпадать по vendor.

### prompts

Промпты **на роль** (conversation system prompt ≠ analysis prompt) — platform слой. User General Prompt живёт в `user_ai_settings`, не заменяет platform prompt. ADR-018.

### ai_settings

Параметры per-role + общие (лимит recent window, флаги фаз, пороги). Не один набор «температура на весь продукт».

### ai_execution_logs

Опционально: что ушло в модель, latency, provider, ошибка. Нужны для диагностики, не для memory. Retention — `TBD`. Не заменять raw messages.

---

## Channels (настройки и сессии)

### telegram_settings

Token (encrypted), webhook, username, статус. Уже концептуально есть в админке. Это конфигурация адаптера, не история чата.

### telegram_groups

IMPLEMENTED M11. Автообнаруженные группы. Source of truth подключения — первый inbound update. ADR-011.

- `id`
- `telegram_chat_id` unique
- `conversation_id` unique FK → `conversations` (`kind=group`)
- `title` / `username` nullable
- `chat_type` (`group` / `supergroup`)
- `status` (`connected` / `restricted` / `left`)
- `timezone` IANA nullable (fallback → owner `users.timezone`)
- `first_seen_at`, `last_seen_at`, `last_message_at`
- `message_count` (increment only on newly created Message)
- `settings` json default `{"mode":"persist_only"}`
- `metadata` json
- timestamps

Не заполняется формой «введите Group ID».

### telegram_group_participants

IMPLEMENTED M11. Unique `(telegram_group_id, telegram_user_id)`. Display name / username / first-last / is_bot / first-last seen. **No FK to users.** Sender_chat / anonymous admin does not create a participant row.

### messages (group columns)

M11 added nullable `telegram_group_id`, `sender_external_id`, `sender_username`, `sender_name`, `reply_to_channel_message_id`, `thread_id`, `edited_at`. Idempotency remains `(channel, conversation_id, channel_message_id)`. `parent_message_id` stays AI reply linkage only. Analysis uses the current body after edit; previous edited text is not analysed in M14.

### telegram_group_analysis_runs

IMPLEMENTED M14. One job/range of Owner Analysis AI over a group.

- `telegram_group_id` FK
- `analysis_type` (`range_bundle` — one run yields summary + decisions + tasks + events)
- `from_at` / `to_at` UTC
- `status` `queued|processing|completed|failed`
- `attempts`, `provider`, `model`, `started_at`, `completed_at`, `last_error`
- `idempotency_key` (group + type + unix from/to); queued/processing runs are reused
- `metadata` json (`no_data`, chunk_count, generated counts)

### telegram_group_knowledge

IMPLEMENTED M14. Derived group facts. **Not** personal `memories`. Owner is `telegram_group_id`.

- `type` `summary|decision|task|event_fact`
- `content`, `title` nullable, `structured_data` json (task assignee/due, decision payload, optional `thread_id`)
- `confidence`, `status` `active|superseded|obsolete|disputed`
- `normalized_key` for MVP dedupe
- `valid_from` / `valid_until`
- `source_from_message_id` / `source_to_message_id`
- `supersedes_id` nullable self-FK
- `generated_by_provider` / `generated_by_model` / `generated_at`
- `analysis_run_id` nullable
- `metadata` json

### telegram_group_knowledge_sources

IMPLEMENTED M14. Unique `(knowledge_id, message_id)`. Required provenance.

### telegram_group_knowledge_revisions

IMPLEMENTED M14. Trail on supersede/reinforce content change.

### admin_audit_logs (концептуально)

Privileged действия: просмотр user/chats, impersonation, смена AI settings, password reset. Имя и обязательность на старте — `TBD`.

### cabinet_sessions / mobile_sessions / desktop_sessions

Сессии кабинета и приложений: auth token/device, last seen. Отдельный permission context от admin session. Impersonation — отдельная сессия/флаг, не пароль user. Не хранят копию памяти.

### voice_sessions

Phase 3: связь с conversation, состояние listening/speaking, provider refs. Аудиофайлы как blob — `TBD` (лучше объектное хранилище, не обязательно в Phase 3 MVP).

### reminders

IMPLEMENTED. См. [REMINDERS.md](REMINDERS.md). `user_id`, source conversation/message, text, `run_at` UTC, timezone, `original_local_time`, status (`scheduled|processing|delivered|cancelled|failed`), recurrence_rule nullable (логика later), metadata json (`attempts`, `next_retry_at`). Indexes: user_id, status, run_at, (status, run_at).

### projects и relations (Owner Space)

IMPLEMENTED M13 + M11 `project_groups`. `projects` (`user_id`, unique `(user_id, normalized_name)`, status `active|archived`). Pivots: `project_conversations`, `project_topics`, `project_memories`, `project_groups` (unique pair, `attached_at`). Cascade pivot on project/entity delete; archive keeps rows. Group attach does not copy raw messages. [PROJECTS.md](PROJECTS.md).

### integration_accounts (M16)

Owner-only connected-account state. Unique `(user_id, provider, external_account_id)`. Status: `disconnected|connecting|connected|error|revoked`. `credentials_encrypted` is Laravel-encrypted JSON (never plaintext tokens). Telegram bot token is **not** stored here. [INTEGRATIONS.md](INTEGRATIONS.md).

### tool_execution_logs (M16)

Owner/user tool audit: tool_name, capability, provider, nullable `integration_account_id`, status (`started|succeeded|failed|denied|confirmation_required`), duration, safe error_code, bounded metadata. No tokens, arguments, or result bodies. Indexes: user_id, tool_name, provider, status, started_at. Retention TBD.

---

## Отношения (кратко)

```
users 1—1 user_profiles
users 1—1 user_ai_settings
users 1—N channel_identities   unique (channel, external_id)
users.access_code unique; один row role=owner
users 1—N conversations (kind=personal; много чатов)
users 1—N reminders
users 1—N projects (owner space)
channel_identities.active_conversation_id → conversations (тот же user_id)
telegram_groups 1—1 conversations (kind=group)
telegram_groups 1—N telegram_group_participants
telegram_groups 1—N telegram_group_knowledge
telegram_group_knowledge 1—N telegram_group_knowledge_sources → messages
telegram_group_knowledge 1—N telegram_group_knowledge_revisions
telegram_groups 1—N telegram_group_analysis_runs
conversations 1—N messages
topics.owner → user | group | global
users 1—N memories (scope=personal + user_id)
group / global memories — свой owner, не чужой user
memories N—N topics (тот же scope)
memories 1—N revisions
memories N—N messages (sources)
conversations 1—N summaries
projects N—N conversations / topics / memories / telegram_groups
project_groups implemented M11; group knowledge via `get_project_context` (M14 derived rows, no extra pivot)
users 1—N integration_accounts
users 1—N tool_execution_logs
integration_accounts 1—N tool_execution_logs
```

---

## Что сознательно не фиксируется

- Индексы и типы колонок.
- JSON vs нормализованные атрибуты фактов.
- Обязательные embeddings-таблицы.
- Multi-tenant «много независимых инстансов». Много users на одном инстансе — да, с isolation.
