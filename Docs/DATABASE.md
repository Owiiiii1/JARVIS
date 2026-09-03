# Концептуальная модель данных

Это **не** финальная schema и **не** задание на migrations. Имена могут измениться. Группы сущностей фиксируют границы, чтобы Phase 1 storage пережил Phase 2. Telegram Groups: [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

Source of truth — relational database (сейчас MySQL в окружении проекта). Vector DB не обязательна.

---

## Identity

### users

Человек в системе Jarvis (владелец ассистента; позже, возможно, админы панели). Ядро работает с `user_id`, не с Telegram id.

### channel_identities

Связь «внешний аккаунт канала ↔ user».

- `user_id`
- `channel` (telegram / mobile / desktop / …)
- `external_id`
- метаданные (username, `TBD`)

Один user — много identity. Разговор в Telegram и в приложении сходится здесь.

---

## Communication

### conversations

Логический тред. Не обязан 1:1 совпадать с Telegram chat навсегда.

- `kind`: `direct` | `group` (личные DM / приложения vs Telegram-группа)
- для `direct`: `user_id` владельца
- для `group`: связь с `telegram_groups` (владелец инстанса не равен «автору каждого сообщения»)
- опционально origin channel
- статус, заголовок, timestamps
- указатель на active topic (`TBD`, скорее Phase 2)

Phase 1: один active **direct** conversation на identity владельца; плюс по одному group conversation на каждую обнаруженную группу.

**Почему общая таблица, а не отдельный message engine.** Group и personal history — разные *контекстные области* (ADR-012), но один pipeline persist / pagination / attachments / идемпотентности. Второй набор таблиц «group_messages» дублировал бы engine без выигрыша. Различие — `conversations.kind` + `telegram_groups` + поля sender на сообщении. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

### messages

Raw history. Закладывается в Phase 1 и **не ломается** в Phase 2. Одна таблица для direct и group.

- `conversation_id`
- `user_id` — владелец инстанса для personal; для group может быть null или тот же owner как «аккаунт бота», не путать с sender
- `role` (user / assistant / system / group_member — точный enum `TBD`)
- `channel` (telegram / mobile / desktop)
- `modality` / `message_type` (text / voice / photo / document / video / …)
- `body` (текст после STT тоже сюда)
- `channel_message_id` / Telegram message ID (идемпотентность)
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

Устойчивые темы (`Jarvis`, `travel`, …). `user_id`, имя, статус, timestamps.

### message_topic_relations

Many-to-many: message ↔ topic, confidence, classifier version. Нужны, чтобы пересчитать классификацию, не трогая messages.

### memories

Долговременные факты/заметки. Не одна куча «всё, что Jarvis знает».

- `scope`: `personal` | `group_knowledge`
- текст/структура факта (`TBD` свободный текст vs typed)
- confidence, status (active / superseded / obsolete / disputed)
- valid_from / valid_until
- `user_id` — владелец инстанса (кому принадлежит запись), не обязательно автор исходной реплики
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

Стабильный профиль: предпочтения, как обращаться, язык, жёсткие ограничения. Один профиль на владельца на старте. Версионирование — `TBD` (можно через revisions).

---

## AI

Конфигурация **ролевая**, не «одна глобальная модель Jarvis». ADR-013.

### ai_providers / ai_provider_settings

Учётные данные и статус провайдеров (в проекте уже есть заготовка таблицы настроек провайдеров в админке). Ядро читает через abstraction, не через UI. Один credential может использоваться несколькими ролями.

### ai_roles / model role mapping

Логические роли — first-class:

| Роль | Обязательность | Назначение |
| --- | --- | --- |
| `conversation` | Phase 1 | общение с владельцем (DM, mobile, desktop, voice) |
| `analysis` | конфиг в Phase 1; jobs в Phase 2 | группы, classification, summarization, extraction, memory processing |

Позже без смены business logic можно добавить: `classification`, `summarization`, `embeddings`, `memory_extraction`, `voice_reasoning`.

Каждая роль: provider, model, credentials reference, prompt, parameters (temperature и др.). Роли **не обязаны** совпадать по vendor.

### prompts

Промпты **на роль** (conversation system prompt ≠ analysis prompt). Редактируются в админке. Версии — желательно (`TBD`), чтобы debug log ссылался на revision.

### ai_settings

Параметры per-role + общие (лимит recent window, флаги фаз, пороги). Не один набор «температура на весь продукт».

### ai_execution_logs

Опционально: что ушло в модель, latency, provider, ошибка. Нужны для диагностики, не для memory. Retention — `TBD`. Не заменять raw messages.

---

## Channels (настройки и сессии)

### telegram_settings

Token (encrypted), webhook, username, статус. Уже концептуально есть в админке. Это конфигурация адаптера, не история чата.

### telegram_groups

Автообнаруженные группы. Source of truth подключения — первый inbound update. ADR-011.

- `id`
- `telegram_chat_id` (уникальный)
- `title`
- `username` / link metadata
- `type` (group / supergroup / …)
- `status` (connected / restricted / left — enum `TBD`)
- `first_seen_at`
- `last_message_at`
- `settings` (policy: persist-only по умолчанию)
- timestamps

Не заполняется формой «введите Group ID».

### telegram_group_participants

Опционально: Telegram user ↔ группа (display name, username, first/last seen). Не обязательно маппить на `users`. Схема не финальная.

### mobile_sessions / desktop_sessions

Сессии приложений: auth token/device, last seen. Не хранят копию памяти.

### voice_sessions

Phase 3: связь с conversation, состояние listening/speaking, provider refs. Аудиофайлы как blob — `TBD` (лучше объектное хранилище, не обязательно в Phase 3 MVP).

---

## Отношения (кратко)

```
users 1—N channel_identities
users 1—N conversations (kind=direct)
telegram_groups 1—1 conversations (kind=group)
telegram_groups 1—N telegram_group_participants
conversations 1—N messages
users 1—N topics
messages N—N topics
users 1—N memories          (scope=personal | group_knowledge)
memories N—N topics
memories 1—N revisions
memories N—N messages (sources + source_kind / source_group)
conversations 1—N summaries
users 1—1 user_profile   (на старте; только personal)
```

---

## Что сознательно не фиксируется

- Индексы и типы колонок.
- JSON vs нормализованные атрибуты фактов.
- Обязательные embeddings-таблицы.
- Мультитенантность «много владельцев Jarvis на одном инстансе».
