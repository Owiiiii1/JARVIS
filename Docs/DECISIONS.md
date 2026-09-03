# Architectural Decision Log

Краткие ADR. Новые решения добавлять сюда, не размазывать по чатам.

Статус: **Accepted** — действующие принципы проекта. Изменение требует новой записи, не молчаливой правки старой.

---

## ADR-001 — Jarvis Core независим от communication channels

**Контекст.** Ассистент появится в Telegram, затем в приложениях и голосе.

**Решение.** Вся оркестрация (user, conversation, memory, AI) живёт в Core. Канал не принимает решений модели.

**Следствие.** Новый канал = новый adapter, не форк ядра.

---

## ADR-002 — Telegram есть первый adapter, не ядро

**Контекст.** Phase 1 = Telegram MVP.

**Решение.** Telegram-код только receive / normalize / send. Nutgram/webhook не вызывают LLM.

**Следствие.** Нельзя «для скорости» положить prompt в Telegram handler. Это блокирует Phase 3.

---

## ADR-003 — Полная raw history независимо от derived memory

**Контекст.** Summaries и facts будут ошибаться и пересчитываться.

**Решение.** Messages сохраняются всегда. Derived layer можно стереть и построить заново.

**Следствие.** Запрещено удалять raw «потому что уже есть summary».

---

## ADR-004 — Phase 2: selective retrieval, не вся история в модели

**Контекст.** Длинный личный контекст не влезает в prompt.

**Решение.** Context package: prompt + working context + релевантные topics/memories + нужные recent messages. Не вся БД.

**Следствие.** Phase 1 recent-window — допустимое упрощение того же интерфейса retriever.

---

## ADR-005 — Relational DB как основное хранилище; Vector DB не обязательна

**Контекст.** Semantic search полезен, но не нужен, чтобы запуститься.

**Решение.** MySQL/relational — source of truth. Embeddings и Vector DB — optional later (`TBD` момент).

**Следствие.** Phase 2 можно начать на SQL + topics. Не ставить Vector DB в prerequisites MVP.

---

## ADR-006 — Mobile и Desktop используют единый backend и единую память

**Контекст.** Иначе появятся три ассистента.

**Решение.** Приложения и Cabinet — клиенты API того же Core. Нет локального memory engine. Речь о **личной** памяти **текущего** `user_id`. Group knowledge не подмешивается автоматически.

**Следствие.** Чаты этого user видны в Cabinet / mobile / desktop / его Telegram DM. Чужой user их не видит. Группы — отдельная область.

---

## ADR-007 — AI providers абстрагированы от business logic

**Контекст.** Провайдеры и модели сменятся.

**Решение.** AI Layer + роли моделей. Core не зависит от одного SDK.

**Следствие.** Админка меняет mapping роль → модель без миграции каналов.

---

## ADR-008 — Voice layer абстрагирован от speech provider

**Контекст.** Предпочтительный TTS/STT — ElevenLabs, рынок сменится.

**Решение.** Порты STT/TTS. ElevenLabs — реализация по умолчанию, не домен.

**Следствие.** Смена vendor не трогает Conversation Engine.

---

## ADR-009 — Admin Panel = конфигурация и диагностика, не источник AI-логики

**Контекст.** Удобно вызвать модель «из настроек».

**Решение.** Админка пишет settings/prompts и показывает логи/чаты. Ответ пользователю всегда через Core.

**Следствие.** Platform prompt принадлежит выбранному conversation config (Owner vs Default User), не «один prompt на весь инстанс». User General Prompt — отдельный слой на user (ADR-018). Analysis — отдельный prompt. Нет скрытой логики и нет прямых вызовов Telegram/LLM из Inertia. Уточнение: ADR-013, ADR-015, ADR-020, ADR-034.

---

## ADR-010 — Phase 4 = conversational intelligence layer, не только prompt engineering

**Контекст.** Естественность требует latency, turn-taking, retrieval, barge-in.

**Решение.** Phase 4 наращивает слой над Core/Memory/Voice, а не заменяет архитектуру одним текстом system prompt.

**Следствие.** Нельзя отложить retrieval и channel-independence «потому что в Phase 4 поправим промптом».

---

## ADR-011 — Telegram Groups регистрируются по первому update

**Контекст.** Бот может быть во многих группах. Ручной ввод Group ID не масштабируется и расходится с реальностью Telegram.

**Решение.** Группы не создаются формой в админке. При первом inbound update из неизвестного `telegram_chat_id` Core создаёт `telegram_groups` (metadata, `first_seen_at`, status) и group conversation. Source of truth подключения — входящий update.

**Следствие.** Админка показывает автообнаруженные группы. Нет обязательного «вставьте ID».

---

## ADR-012 — Group conversations и personal memory — разные области

**Контекст.** Рабочая группа порождает решения и задачи, которые не являются фактами о владельце.

**Решение.** Group history и group knowledge отделены от personal conversation history и personal memory. Memory Engine хранит scope и provenance. История группы не становится personal fact автоматически.

**Следствие.** Analysis пишет в group knowledge. Conversation package видит это только если запрос про группу (или явный перенос, `TBD`).

---

## ADR-013 — Role-based AI configuration

**Контекст.** Общение и анализ — разные нагрузка, цена и промпты. Один vendor на всё — ложная экономия архитектуры.

**Решение.** Независимые configuration domains. Уточнение ADR-034 / ADR-035: минимум **Owner Conversation AI**, **Owner Analysis AI**, **Default User Conversation AI**. Каждая: provider, model, credentials reference, prompt, parameters. Не обязаны совпадать. Позже слоты classification / summarization / embeddings без смены business logic.

**Следствие.** Запрещена архитектура «одна глобальная модель Jarvis» и «одна Conversation AI на owner и users». Админка конфигурирует domains раздельно.

---

## ADR-014 — Group messages сохраняются как raw до анализа

**Контекст.** Summaries и extract по группам будут ошибаться и пересчитываться.

**Решение.** Все увиденные сообщения зарегистрированной группы пишутся в raw history (тот же message engine, `kind=group`) до любой аналитики. Analysis не заменяет persist.

**Следствие.** Нельзя удалять group raw «потому что есть выжимка». Не слать всю ленту модели на каждый запрос.

---

## ADR-015 — Исходящие в группу из админки идут через Telegram Channel Adapter

**Контекст.** Удобно вызвать Bot API из UI или отдельного «admin telegram client».

**Решение.** `Admin UI` → Group Messaging Service → **тот же** Telegram Channel Adapter → Bot API. После успеха — persist в group history. UI не вызывает Telegram API.

**Следствие.** Один путь outbound для бота. Согласовано с ADR-002 и ADR-009.

---

## ADR-016 — Изоляция user context

**Контекст.** Несколько людей на одном backend и одном боте.

**Решение.** У каждого user независимые profile, conversations, messages, topics, memories, summaries, AI settings, cabinet access. Retrieval всегда scoped by `user_id` (или явный иной owner scope). Context user A не попадает к user B.

**Следствие.** Общий provider/модель/бот не означают общую память. Глобальный unscoped memory search запрещён.

---

## ADR-017 — New Chat не создаёт новую long-term memory

**Контекст.** Несколько независимых чатов в Cabinet.

**Решение.** Новая conversation имеет пустую raw history. Long-term memory принадлежит user и может использоваться во всех его чатах, если retrieval признал факт релевантным. Сырые сообщения других чатов не копируются.

**Следствие.** `New Chat ≠ New User Memory`. Обнуление чата ≠ обнуление профиля/memories.

---

## ADR-018 — User General Prompt поверх platform rules

**Контекст.** Ребёнку нужен другой стиль, чем владельцу. Platform safety и изоляция общие.

**Решение.** User General Prompt — отдельный слой. Hierarchy: platform/system → channel rules → user prompt → memory → topics → conversation history → current message. User prompt не отменяет критические platform rules.

**Следствие.** Не хранить «один personality prompt на весь инстанс» как единственную личность всех users.

---

## ADR-019 — Platform defaults и optional per-user AI override

**Контекст.** Не у каждого user свой vendor.

**Решение.** Default User Conversation AI — отдельный platform default, **не** наследует Owner Conversation AI. Optional later: per-user model override **поверх Default User Conversation AI**. `resolveConversationAI(user)`.

**Следствие.** Пустой override = Default User Conversation AI. User никогда не получает Owner Conversation config «по умолчанию».

---

## ADR-020 — Admin открывает Cabinet через impersonation

**Контекст.** Диагностика кабинета. Пароль user нельзя показывать.

**Решение.** Open Cabinet создаёт impersonated session. UI показывает режим, есть выход, действие логируется. Пароль не передаётся и не отображается.

**Следствие.** Запрещён «войти, подсмотрев пароль». Запись в чат от имени user — не часть этого ADR.

---

## ADR-021 — User resources защищены ownership layer

**Контекст.** URL с id легко перебрать.

**Решение.** Все user endpoints проверяют, что ресурс принадлежит текущему user (или это явный privileged admin action). Policies / authorization в Core, не только UI.

**Следствие.** Залогиненный user B не читает `/conversations/123` user A.

---

## ADR-022 — Граница ролей owner и user

**Контекст.** Сейчас любой `users` row — полный админ.

**Решение.** Две роли: `owner` (`*`) и `user` (cabinet + свой Telegram DM). Один owner на инстанс. Не hardcode `user_id`. User не получает Admin Panel, Settings, Groups, integrations, чужие данные. Enforcement в backend.

**Следствие.** Login owner → admin; login user → cabinet.

---

## ADR-023 — Owner access code `2000`

**Контекст.** Нужен человекочитаемый Telegram pairing для владельца.

**Решение.** Зарезервированный уникальный `access_code=2000` у owner. Это **не** web-пароль.

**Следствие.** Генератор обычных кодов не выдаёт `2000`.

---

## ADR-024 — Уникальные access codes

**Контекст.** Pairing не должен использовать email или id.

**Решение.** У каждого User уникальный human-readable `access_code`. Unique constraint + retry в приложении. Owner видит код и может regenerate.

**Следствие.** Коллизии на уровне БД невозможны.

---

## ADR-025 — Telegram pairing через access code

**Контекст.** Webhook ACK-only; бот молчит. Нужна авторизация канала.

**Решение.** Несвязанный Telegram: `/start` просит код; текст = попытка кода. Успех → identity. Неуспех → отказ. AI не вызывается до успеха.

**Следствие.** Транспорт webhook + Nutgram.

---

## ADR-026 — channel_identity после pairing

**Контекст.** Нужна устойчивая связь Telegram ↔ User.

**Решение.** Строка `channel_identities`: telegram, external_user_id unique, names, user_id, linked_at. Дальше сообщения резолвят User без кода.

**Следствие.** Один Telegram id — один User. Обратно: один User — одна Telegram identity на MVP (ADR-046).

---

## ADR-046 — Один Telegram identity на Jarvis User (MVP)

**Контекст.** User мог бы иметь несколько Telegram accounts; это усложняет pairing и admin UX на Phase 1.

**Решение.** На MVP у каждого `users` row не более одной `channel_identities` записи с `channel=telegram`. Вторая попытка pairing для того же User отклоняется. Переподключение другого Telegram account — только после owner unlink.

**Следствие.** Pairing service проверяет существующую identity User до создания новой. Regenerate access code не unlink-ит Telegram автоматически.

---

## ADR-027 — Telegram не создаёт User

**Контекст.** Удобно завести аккаунт с первого `/start`.

**Решение.** Запрещено. User создаёт только owner (каталог). Неизвестный код не регистрирует человека.

**Следствие.** Нет открытой саморегистрации через бота.

---

## ADR-028 — Integrations только owner

**Контекст.** Gmail/Calendar/ElevenLabs на инстансе.

**Решение.** Tool/Integration Layer доступен `role=owner`. User не видит Integrations и не получает эти tools.

**Следствие.** Groups admin тоже owner-only (согласовано с ADR-012).

---

## ADR-029 — Google через Tool Layer

**Контекст.** Можно вызвать Calendar API из Telegram handler.

**Решение.** Google Calendar и Gmail — adapters Tool Layer. Conversation AI делает tool calls. Не вшивать Google в Nutgram/Telegram adapter.

**Следствие.** Тот же tool доступен из cabinet/API owner, не только из Telegram.

---

## ADR-030 — Строгий ownership

**Контекст.** User и owner на одном API.

**Решение.** Все personal resources scoped `user_id`. Owner читает чужое только через явные admin endpoints (User Card / impersonation), не через cabinet API жертвы без audit.

**Следствие.** Усиливает ADR-021.

---

## ADR-031 — Owner — обычный User в Conversation Core

**Контекст.** Иначе появится второй AI pipeline.

**Решение.** Owner имеет personal history, topics, memories, Telegram DM, Conversation AI как любой User, плюс administrative role.

**Следствие.** Pairing `2000` — тот же механизм, что чужой код.

---

## ADR-032 — Owner Space и User Spaces изолированы

**Контекст.** Различия только через `role` недостаточно: легко смешать context.

**Решение.** Логические пространства. Owner Space и каждый User Space полностью изолированы: conversations, summaries, memory, General Prompt, Telegram DM, reminders, timezone. Owner personal context не смешивается с User contexts. User A не видит User B.

**Следствие.** Isolation = scope / ownership / configuration, не второй продукт.

---

## ADR-033 — Общие engines, раздельные scopes

**Контекст.** Иначе появится N реализаций Conversation Engine.

**Решение.** Общие технические engines: Conversation, Context Builder, Telegram Adapter, Reminder Engine, AI Provider Layer. Различие — `user_id`, capabilities, AI config domain.

**Следствие.** Новый permission не создаёт новый engine.

---

## ADR-034 — Owner Conversation AI ≠ Default User Conversation AI

**Контекст.** Owner может держать дорогую модель; users не должны её наследовать.

**Решение.** Два независимых conversation configs. User не резолвит Owner Conversation AI. Optional later: per-user override поверх Default User Conversation AI.

**Следствие.** Уточняет ADR-013 и ADR-019.

---

## ADR-035 — Owner Analysis AI отдельный

**Контекст.** Groups, summaries, extract, project analysis — другая нагрузка.

**Решение.** Owner Analysis AI — отдельный provider/model/prompt. Не обслуживает обычный user DM. Не обязан совпадать с Owner Conversation AI.

**Следствие.** Jobs и DM не делят один «активный» model switch.

---

## ADR-036 — Cross-chat: summary-first / raw-on-demand

**Контекст.** Нельзя считать, что каждый chat знает raw всех остальных.

**Решение.** В пакет: current raw/recent + relevant summaries других chats того же user + structured memory. Raw другого чата — только targeted retrieval. Cross-user retrieval запрещён.

**Следствие.** New Chat пустой по raw, не «амнезия профиля».

---

## ADR-037 — Telegram выбирает active conversation

**Контекст.** Telegram ≠ один вечный conversation. Cabinet уже предполагает каталог.

**Решение.** Один каталог conversations на space. `channel_identities.active_conversation_id` (тот же `user_id`). Меню «Чаты»: выбрать / создать / текущий. Первый pairing: conversation `Основной`, active, greeting туда.

**Следствие.** Web и Telegram видят одни и те же chats.

---

## ADR-038 — Reminders — Core subsystem, не Google Calendar

**Контекст.** Легко отдать «напомни» в Calendar API.

**Решение.** Reminder Engine — своя сущность и pipeline: Conversation AI → Reminder Tool → Engine → DB → worker → Telegram. Calendar — другой owner tool.

**Следствие.** «Напомни завтра» ≠ calendar event. Доступно owner и users.

---

## ADR-039 — Reminder delivery сейчас Telegram-only

**Контекст.** Нет product-ready web/mobile push.

**Решение.** Доставка только через Telegram Adapter. Нет web/email/mobile/desktop notification. Без linked Telegram identity — сообщить, что нужно подключить Telegram.

**Следствие.** Cabinet может создать reminder текстом, но канал доставки — бот.

---

## ADR-040 — Timezone per user и per group

**Контекст.** «Завтра в 11» и «сегодня в группе» без TZ бессмысленны.

**Решение.** `users.timezone` IANA для relative dates и reminders (`run_at` UTC). `telegram_groups.timezone` для group date queries; fallback → owner timezone.

**Следствие.** Не интерпретировать «сегодня» в TZ сервера.

---

## ADR-041 — Project ≠ Topic

**Контекст.** Тема классификации ≠ рабочий контейнер.

**Решение.** Projects — сущность Owner Space. Relations к conversations/topics/groups/memories/group knowledge, без копирования raw. Users на MVP не получают Projects.

**Следствие.** Topic `Jarvis` и project `JARVIS` — разные вещи.

---

## ADR-042 — Group knowledge в Owner AI только через explicit tool

**Контекст.** Auto-merge всех групп в owner prompt сломает личный контекст и окно модели.

**Решение.** Group knowledge не personal memory. Owner Conversation AI ходит в stored raw/derived через Group Search/Analysis Tool по запросу.

**Следствие.** «Что решили в группе 1?» — tool. Молчаливого подмешивания нет.

---

## ADR-043 — Hierarchical analysis больших group histories

**Контекст.** Вся raw history групп в нашей DB — нормально. Один prompt на archive — нет.

**Решение.** Date range per group TZ → retrieve → chunk → analyse → aggregate per group → reduce across groups. Jobs/queue. Не зависеть от одного context window.

**Следствие.** Analysis AI не обязан «видеть всё сразу».

---

## ADR-044 — Tool loop: несколько calls в одном turn

**Контекст.** «Свободен завтра и поставь встречу» = free/busy + create.

**Решение.** Conversation Engine поддерживает последовательные tool calls в одном turn. Не `one message = max one tool call`. Read-only обычно без confirm; явная команда авторизует write; самопредложенный write — confirm; destructive — повышенный confirm.

**Следствие.** Multi-step Google/Gmail/reminders/group search — один user message.

---

## ADR-045 — Capability layer поверх roles

**Контекст.** Десятки `if role === owner` размажут политику.

**Решение.** Capabilities (`chat`, `memory`, `telegram_dm`, `reminders`, `projects`, `telegram_groups`, `group_analysis`, `gmail`, `google_calendar`, `integrations_admin`, `users_admin`, `voice`, `impersonation`). Role задаёт default set. Проверка в Core. Owner = все; user сейчас = chat/memory/telegram_dm/reminders/cabinet.

**Следствие.** Новые permissions без нового Conversation Engine.

---

## ADR-046 — Reminder без Telegram identity не создаётся

**Контекст.** Delivery сейчас только Telegram. Можно было бы сохранить scheduled reminder до pairing.

**Решение.** Если у User нет linked Telegram identity, reminder **не создаётся**. Сообщение: «Для получения напоминаний сначала подключите Telegram.» Не копить undeliverable rows.

**Следствие.** Tool result `telegram_not_connected`. Web Cabinet тоже требует Telegram pairing для create.

---

## ADR-047 — Current local time инжектится каждый turn

**Контекст.** Модель должна понимать «завтра», «через два часа» без hardcoded даты.

**Решение.** На каждом conversation turn в system context: current user local datetime (ISO с offset) и `users.timezone`. Не hardcode.

**Следствие.** Tool получает structured `run_at_local`; Core применяет IANA timezone (DST). Offset, противоречащий IANA, игнорируется в пользу IANA.

---

## ADR-048 — One-time reminders; recurrence later

**Контекст.** Schema имеет `recurrence_rule`. Пользователь может сказать «каждый день в 9».

**Решение.** Recurrence не реализуется в этом milestone. AI сообщает, что повторяющиеся напоминания не поддерживаются. Не создавать one-time reminder как подмену recurring.

**Следствие.** `create_reminder` — единственный reminder tool сейчас. list/cancel later.

---

## Открытые решения (`TBD`)

- Алфавит generated access_code (кроме зарезервированного 2000).
- 403 vs redirect когда user открывает admin URL.
- Есть ли у owner отдельный cabinet UI или «мои чаты» в админке.
- Технология очередей.
- Auth схема mobile/desktop.
- Realtime транспорт voice/text streaming; STT/TTS/interruption — практические тесты.
- Пороги confidence и summarization.
- Набор service updates (`my_chat_member`).
- Retention raw messages по закону/желанию пользователя (отдельно от derived lifecycle).
- UX явного переноса group knowledge → personal fact.
- Persisted capability overrides (сейчас достаточно default из role).
