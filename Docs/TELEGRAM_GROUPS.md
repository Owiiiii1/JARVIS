# Telegram Groups

Отдельный модуль Jarvis. Бот может состоять во многих Telegram-группах. Группы **не** создаются вручную в админке: факт подключения появляется из входящего Telegram update. ADR-011.

Telegram остаётся **channel adapter**. Модуль Groups живёт в Core (регистрация, persistence, политики, анализ) и в Admin Panel (просмотр и исходящие сообщения). Вызовы Bot API — только через Telegram Channel Adapter. ADR-015.

Личные DM любого Jarvis User и групповые чаты — **разные области**. ADR-012.

**Admin Groups и group knowledge — только Owner Space** (`telegram_groups`, `group_analysis`). Обычный user не видит группы и не получает group knowledge в personal prompt.

Каждая группа: `timezone` (IANA). Owner задаёт в Group Settings. Для today/yesterday/morning/daily summaries / date-range. Если пусто — **owner timezone**.

Подробности памяти: [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md). Роли моделей: [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md).

---

## Назначение

- Принимать и **сохранять** сообщения из групп (raw history).
- Показывать группы и читаемый чат в админке.
- Давать администратору отправить сообщение в группу от имени бота.
- По умолчанию **не отвечать** в группе (passive monitoring).
- Позже анализировать историю через **Analysis AI**, не смешивая результат с персональной памятью без явного provenance.

Это не второй ассистент и не CRM.

---

## Discovery и регистрация

Группы не вводятся руками (никакого обязательного «вставьте Group ID»).

### Условия

1. Бот добавлен в группу.
2. У бота достаточно прав, чтобы получать нужные апдейты (конкретный набор прав — `TBD`: как минимум чтение сообщений; для forum topics / voice / media — по мере поддержки типов).
3. Backend получает первое сообщение (или иной релевантный update) из ещё неизвестного `telegram_chat_id`.

### Алгоритм первого update

1. Adapter нормализует update и видит `chat.type` ∈ group / supergroup / forum (`TBD` точный набор; channels Telegram — отдельное решение, не смешивать молча).
2. Core ищет `telegram_groups` по `telegram_chat_id`.
3. Если нет — создаёт запись:
   - `telegram_chat_id`;
   - title (если есть);
   - Telegram chat type;
   - username / invite metadata, если доступны;
   - `first_seen_at`;
   - `status` (например `connected` / `restricted` / `left` — точный enum `TBD`);
   - пустые или дефолтные `settings` (режим: persist-only).
4. Создаёт (или привязывает) **group conversation** — отдельный тред, не personal DM.
5. Сохраняет raw message.
6. Группа сразу видна в админке.

Повторные апдейты той же группы обновляют `last_message_at`, title/username при изменении, счётчики. Не создают дубликат.

Source of truth факта «группа подключена» — входящий update, не форма в админке.

### Служебные события

`my_chat_member` / kick / смена прав: обновлять `status`, не удалять историю. Точный набор обрабатываемых service updates — `TBD`.

---

## Persistence сообщений

Все полученные из зарегистрированной группы сообщения сохраняются **до** аналитики. ADR-014.

Group conversations логически отделены от personal direct conversations. Технически используется **тот же message engine** (общая таблица `messages` + `conversations.kind`), чтобы не плодить второй пайплайн пагинации, вложений и идемпотентности. См. [DATABASE.md](DATABASE.md).

### Поля сообщения (концептуально)

Сохранять всё доступное, даже если UI на первом этапе показывает только текст:

- Telegram message ID;
- Telegram group / chat ID (через `telegram_groups` / conversation);
- sender Telegram user ID;
- sender name / username;
- reply-to (relation на другое raw message или external id);
- thread / forum topic ID, если Telegram отдаёт;
- text;
- message type;
- attachments metadata (не обязательно blob в MySQL);
- timestamp;
- edited status;
- raw Telegram metadata при необходимости (JSON), чтобы не потерять поля до расширения модели.

### Типы, которые модель не должна блокировать

- text;
- voice;
- photo;
- document;
- video;
- reply;
- forwarded;
- Telegram forum topics.

Первый этап реализации может писать и показывать только text (+ заглушка «unsupported type» для остальных). Схема и нормализация должны допускать расширение без смены engine.

Идемпотентность: уникальность `(channel, telegram_chat_id, telegram_message_id)` или эквивалент на `channel_message_id`.

Редактирование: обновлять raw + флаг edited, не плодить вторую «истину» без revision trail (`TBD` хранить предыдущий текст).

---

## Participants

Опциональная сущность `telegram_group_participants`:

- связь telegram user ↔ группа;
- display name / username на момент последнего сообщения;
- first/last seen;
- не обязательно маппить каждого участника на `users` ядра.

Владелец Jarvis — `users`. Участники группы — внешние identity. Путать их с personal `user_id` нельзя. Анализ «что обещал Иван» опирается на participant identity + текст, не на personal memory Ивана.

---

## Admin Panel

Раздел **Telegram Groups** (отдельный пункт админки, не вкладка «ввести ID»).

### Список

Для каждой автоматически найденной группы минимум:

- название;
- Telegram ID;
- статус;
- дата обнаружения;
- дата последнего сообщения;
- количество сохранённых сообщений;
- статус мониторинга / policy (`TBD`, на старте достаточно «persist only»).

Ручное создание группы не требуется. Ручное скрытие/архив — `TBD`, не путать с удалением raw.

### Страница группы — chat UI

Не лог событий. Обычный messenger:

- пузыри по хронологии;
- автор и время;
- визуально отличить участников и сообщения Jarvis (бот / исходящие из админки);
- пагинация или lazy loading;
- поддержка reply/thread в UI — по мере данных (`TBD` глубина первого экрана).

Администратор читает Telegram-диалог внутри админки.

### Исходящее сообщение

Поле ввода на странице группы.

```
Admin UI
  → Group Messaging Service (Core)
    → Telegram Channel Adapter
      → Telegram Bot API
        → группа
```

После успеха — то же сообщение в raw history группы (как исходящее от бота).

**Запрещено** вызывать Bot API из Inertia/React. ADR-015, ADR-009.

Ошибки API (нет прав, бот кикнут) — статус группе, сообщение в UI, без «тихого» успеха.

---

## Passive monitoring

Основной режим: **не отвечать** на каждое сообщение.

Путь группы:

```
Telegram group message
  → Telegram Adapter
  → normalize
  → persist raw message
  → optional lightweight processing
  → available for Analysis Engine
  → optional topic/memory extraction (не в personal memory автоматически)
  → no automatic response unless group policy requires it
```

Lightweight processing на первом этапе: обновить counters, participant, title. Без LLM в sync-пути группы.

Conversation Engine для **личных** DM по-прежнему отвечает. Групповой inbound **не** должен автоматически входить в personal reply path.

---

## Group policies (будущее)

Расширяемые настройки на `telegram_groups.settings`, не хардкод в адаптере:

- только сохранять (дефолт);
- отвечать при mention / reply на бота;
- разрешить активные ответы;
- periodic summaries;
- извлекать tasks / decisions / important events.

Первый этап документирует слот политики; реализация — later. Analysis jobs читают policy, adapter её не интерпретирует как AI.

---

## Analysis

Зачем хранить группы: последующие вопросы владельца, например:

- что сегодня обсуждали в группе X;
- какие решения приняли;
- что про проект Jarvis;
- что обещал Иван;
- новые дедлайны;
- выжимка за неделю;
- найти обсуждение проблемы.

Делает **Owner Analysis AI**, не User Conversation AI. Вся raw history групп хранится в нашей DB — это нормально. **Никогда** не слать весь archive одним prompt.

Derived types (group knowledge, не personal memory):

| Type | Смысл |
| --- | --- |
| Summary | что обсуждали |
| Decision | что решили |
| Task | кто что должен |
| Event / Fact | что произошло / изменилось |

У каждой: group provenance, source messages, timestamps, confidence, analysis model metadata.

### Hierarchical analysis (большие объёмы)

Запрос «анализ за сегодня по всем группам»:

1. date range **per group timezone** (fallback owner timezone);
2. retrieve messages;
3. chunk;
4. analyse chunks;
5. aggregate per group;
6. reduce across groups.

Jobs/queue. Не зависеть от одного context window.

### Owner personal chat → group knowledge

Не auto-mix в Owner personal memory. По запросу:

```
Owner Conversation AI
  → Group Search / Analysis Tool
  → stored raw/derived
  → result
  → ответ
```

Примеры: «анализ за сегодня по всем группам», «что решили в группе 1?», «важное по JARVIS?». Не класть все группы в каждый prompt.

---

## Связь с Memory Engine

История группы **не** становится автоматически personal memory. ADR-012.

Разделить области:

| Область | Примеры |
| --- | --- |
| Personal conversation history | DM владельца с Jarvis |
| Personal memory | факты о владельце, его решения в личке |
| Group conversation history | raw сообщений группы |
| Group knowledge | «в группе X решили сменить API» |

Каждая derived memory несёт provenance:

- source kind: `direct_conversation` / `telegram_group` / `summary` / `manual_admin` / иное;
- ссылки на group id и message ids;
- conversation id при необходимости.

Group knowledge можно **показать** Conversation model, если запрос владельца про эту группу. Нельзя молча влить в user profile.

Пересчёт group summaries не удаляет raw group messages. ADR-003, ADR-014.

---

## Permissions

- Недостаточные права бота: группа всё равно может появиться по первому увиденному update; `status` отражает ограничение.
- Бот покинул группу: history остаётся, inbound прекращается, outbound падает явно.
- Privacy Telegram (privacy policy бота, anonymous admins) — сохранять то, что API отдаёт; не выдумывать sender. `TBD` нюансы.

---

## Масштабирование

Много групп × высокая частота сообщений:

- persist в sync, analysis только async;
- не вызывать Analysis LLM на каждое групповое сообщение по умолчанию;
- индексы по `telegram_chat_id`, time, conversation_id (`TBD` при реализации);
- lazy load в админке;
- очередь analysis jobs — та же `TBD` инфраструктура, что в [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md).

Не вводить отдельный «group microservice» без нужды. Тот же Core, тот же adapter.

---

## Фазы

| Phase | Groups |
| --- | --- |
| 1 | Discovery, persist, админ-список и chat UI, outbound через adapter, passive, Owner Analysis AI в конфиге (может ещё не гоняться); group timezone |
| 2 | Analysis jobs, group knowledge + provenance, selective retrieval для вопросов про группу |
| 3–4 | Те же данные; клиенты не обязаны дублировать group admin UI (`TBD`, админка остаётся основным просмотром групп) |

---

## Что не делать

- Не требовать ручной ввод Group ID как единственный способ регистрации.
- Не отвечать в группе по умолчанию.
- Не писать group history в personal facts без provenance.
- Не вызывать Telegram API из UI.
- Не кормить Analysis или Conversation всей лентой группы.
- Не делать отдельный LLM-стек внутри Telegram adapter.
- Не давать `role=user` доступ к group admin, group send или group knowledge в personal prompt.
