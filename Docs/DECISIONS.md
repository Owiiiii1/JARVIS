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

**Следствие.** Analysis пишет в `telegram_group_knowledge` (M14), never into personal `memories`. Conversation package does not auto-mix group knowledge. M14 may surface bounded derived facts via `get_project_context` when a group is attached to a project. M15 owner DM/Cabinet calls `search_group_knowledge` explicitly.

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

**Решение.** Group knowledge не personal memory. Owner Conversation AI ходит в stored derived, then bounded raw, через explicit `search_group_knowledge` (capability `group_analysis`). Access scope is `ToolExecutionContext.user`, never model arguments. Missing/stale analysis may queue M14; the personal turn does not wait.

**Следствие.** «Что решили в группе 1?» — tool. Молчаливого подмешивания нет. Normal user не видит definition и не может forged-execute.

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

## ADR-049 — Derived memory is async

**Контекст.** Extraction/summarization на Owner Analysis AI не должны увеличивать Telegram/Web latency.

**Решение.** После persist inbound → Conversation AI → persist assistant → ответ пользователю. Затем `AnalyzeConversationTurnJob` / `UpdateConversationSummaryJob` на queue `memory`. Conversation turn не ждёт Analysis AI.

**Следствие.** Structured memory может появиться с небольшой задержкой после реплики.

---

## ADR-050 — Relational retrieval first

**Контекст.** Vector DB улучшает semantic search, но не нужна, чтобы изолировать users и не раздувать prompt.

**Решение.** M12 retrieval — MySQL: `user_id` first, keyword/`normalized_key`/topic, confidence, freshness, validity. Нет Pinecone/Qdrant/Weaviate/pgvector.

**Следствие.** Embeddings — later, когда relational quality станет узким местом.

---

## ADR-051 — Raw-on-demand via Core tool

**Контекст.** Summary-first недостаточно, если пользователь спрашивает деталь старого чата.

**Решение.** Tool `search_conversation_history`. Модель не передаёт `user_id`. Core использует `ToolExecutionContext.user`. Bounded snippets. Чужие conversations игнорируются.

**Следствие.** Chat A не получает raw Chat B автоматически.

---

## ADR-052 — Analysis AI processes derived memory; scope stays user_id

**Контекст.** Один Analysis config проще, чем отдельная модель на каждого user.

**Решение.** Owner Analysis AI — background engine для extract/summary любых users. `user_id` всегда задаёт Core. Model-generated user_id игнорируется. Derived rows User A никогда не читаются в retrieval User B.

**Следствие.** Не нужен отдельный User Analysis AI в M12.

---

## ADR-053 — Queue worker via systemd

**Контекст.** Database queue без worker не обрабатывает memory jobs. Telegram уже имеет crontab flock worker на queue `telegram`.

**Решение.** `jarvis-queue.service` (`queue:work database --queue=memory,default`). Telegram worker crontab не дублируется этим unit. Reminder scheduler cron не трогается. Supervisor не ставится.

**Следствие.** Memory jobs и default queue обрабатывает systemd; Telegram updates — отдельный worker.

---

## ADR-054 — Project context is tool-driven

**Контекст.** Все owner projects в каждый prompt раздуют окно и смешают несвязанную работу.

**Решение.** Projects не входят в `ConversationContextBuilder` по умолчанию. Owner Conversation AI вызывает `get_project_context` по релевантному запросу. Capability `projects` только у owner.

**Следствие.** «Привет» не получает JARVIS/YFS/RTS.

---

## ADR-055 — No project_groups until Groups subsystem exists

**Статус.** Superseded by ADR-056 / M11.

**Контекст.** Telegram Groups ещё не были реализованы в M13.

**Решение (M13).** Не создавать `project_groups`.

**Следствие.** M11 создал `project_groups` после появления Groups subsystem.

---

## ADR-056 — Group conversation administrative owner vs personal boundary

**Контекст.** `conversations.user_id` NOT NULL. Делать его nullable в M11 рискованно для personal DM.

**Решение.** Group conversation использует owner `user_id` как administrative owner. Обязательная граница: `kind=group` + `telegram_groups.conversation_id`. Cabinet, Telegram chat selector, Conversation AI, PersonalMemoryRetriever, history search и memory jobs фильтруют `kind=personal`.

**Следствие.** `user_id=owner` на group conversation не делает её personal chat.

---

## ADR-057 — Group inbound never enters personal Conversation AI

**Контекст.** Group text can look like a personal prompt («Jarvis, привет»).

**Решение.** `chat.type` group/supergroup → Groups subsystem only: persist, participants, counters. No `ConversationTurnService`, no Conversation AI, no `AnalyzeConversationTurnJob`, no personal topics/memories. Mentions do not change this in M11.

**Следствие.** Routing is by Telegram chat type, not by linked ChannelIdentity.

---

## ADR-058 — Group Privacy mode is a manual Telegram prerequisite

**Контекст.** With privacy ON, Telegram does not send most group messages to the bot.

**Решение.** Jarvis persists every group update it receives. Full-history monitoring requires the owner to disable BotFather Group Privacy and grant needed group rights. Cursor never changes BotFather settings or claims full monitoring without Telegram delivering updates.

**Следствие.** Empty Admin history can mean Telegram never sent the messages.

---

## ADR-059 — Group participant is not a Jarvis User

**Контекст.** Numeric Telegram user id may match a linked owner identity.

**Решение.** `telegram_group_participants` has no FK to `users`. Participant rows exist only for a real Telegram `from` user. `sender_chat` / anonymous admin stores sender metadata on the message only.

**Следствие.** Group analysis attributes text to participants / display names, not personal memory of a Jarvis User. M14 writes `telegram_group_knowledge` only.

---

## ADR-060 — Group knowledge is a separate table, not personal memories

**Контекст.** Personal Memory Engine already uses `memories` with `scope=personal` + `user_id`. Reusing that table for Telegram groups would mix owner facts with group chat extract, including when the owner authored a group message.

**Решение.** M14 stores derived group facts in `telegram_group_knowledge` keyed by `telegram_group_id`. Provenance is `telegram_group_knowledge_sources`. Runs are `telegram_group_analysis_runs`. Analysis is manual/async Owner Analysis AI (queue `analysis`). Hierarchical chunk/reduce; structured JSON validation; dedupe via `normalized_key`; supersede via status + revisions. Group tasks are not Reminders. `analysis_enabled` / `daily_summary_enabled` default false. M15 reads this layer via `search_group_knowledge`; it does not write personal `memories`.

**Следствие.** PersonalMemoryRetriever and ConversationContextBuilder ignore group knowledge. Project context may include bounded ACTIVE derived rows only. Owner group questions use `search_group_knowledge`.

---

## ADR-061 — Integration registry in code, accounts in DB

**Контекст.** Можно хранить список провайдеров в таблице и динамически резолвить классы.

**Решение.** `IntegrationRegistry` в коде. `integration_accounts` хранит только connected state / credentials. Telegram status — virtual bridge без обязательной DB-строки.

**Следствие.** Новый provider = новый adapter + register(), не row «class name» в MySQL.

---

## ADR-062 — Credentials encrypted and never serialized

**Контекст.** Access/refresh tokens в plaintext JSON опасны в dump и Inertia.

**Решение.** Laravel `encrypted:array` на `credentials_encrypted`. Model hidden + `toArray` strip. Adapter getter only. Logs/UI never receive the blob or plaintext.

**Следствие.** DB dump не содержит usable tokens.

---

## ADR-063 — Telegram integration card reuses Channel source of truth

**Контекст.** Второй store bot token в `integration_accounts` разъедется с Settings → Telegram.

**Решение.** `TelegramIntegrationProvider` читает `telegram_bot_settings`. Не копирует token. Не создаёт account row ради UI.

**Следствие.** Один token store. Integrations card — overview.

---

## ADR-064 — ToolExecutionService centralizes logs and policy

**Контекст.** Логирование в каждом tool разъедется и начнёт писать секреты.

**Решение.** `ToolRegistry::execute` делегирует `ToolExecutionService`: capability, confirmation policy, `tool_execution_logs`, safe metadata, account last_used/error.

**Следствие.** Core и future integration tools проходят один pipeline. Multi-tool loop не схлопывается в one-call.

---

## ADR-065 — Model cannot self-authorize writes

**Контекст.** Модель может передать `authorized=true`.

**Решение.** Права только из `ToolExecutionContext.user` и server-side `explicitUserCommand`. Model arguments `authorized` / `confirmation` / `user_id` / `integration_account_id` игнорируются.

**Следствие.** Confirmation policy скелет готов для M18/M19. Precise NLP explicit-intent detection может эволюционировать.

---

## ADR-066 — OAuth state is server-side session controlled

**Контекст.** Callback без state — CSRF/account mix-up.

**Решение.** Cryptographic state + PKCE verifier in the owner session, TTL, one-time consume, bound to `user_id`. Return path is always Settings → Integrations.

**Следствие.** Invalid/expired/used state rejects without token exchange.

---

## ADR-067 — Google tokens only in encrypted IntegrationAccount credentials

**Контекст.** Client secret и user tokens в одном store или в UI.

**Решение.** Client ID/Secret = env. User access/refresh = `credentials_encrypted`. Never serialize to Inertia/JSON/logs.

**Следствие.** DB dump без APP_KEY не даёт usable Google tokens.

---

## ADR-068 — Refresh only through GoogleCredentialService

**Контекст.** M18/M19 adapters могут прочитать plaintext envelope.

**Решение.** Единственный путь к access token — `GoogleCredentialService::getValidAccessToken()`. lockForUpdate + refresh skew.

**Следствие.** Core не знает имена Google token fields.

---

## ADR-069 — Existing refresh_token is never overwritten by an absent response

**Контекст.** Google часто не возвращает refresh_token на повторный consent.

**Решение.** `mergeTokenResponse` сохраняет предыдущий refresh_token, если incoming пустой.

**Следствие.** Reconnect не ломает offline access.

---

## ADR-070 — One active Google account per owner (MVP)

**Контекст.** Schema допускает несколько Google accounts.

**Решение.** M17 UI — один active. Same `sub` updates. Different `sub` disconnects the previous connected account (revoke + wipe). Rows are not silently deleted.

**Следствие.** Нет duplicate connected Google accounts.

---

## ADR-071 — OAuth connection does not enable AI tools

**Контекст.** Connected Google может выглядеть как Calendar/Gmail ready.

**Решение.** M17 connection does not register Calendar/Gmail tools. M18 registers Calendar tools by capability; runtime still requires a connected account and Calendar scope.

**Следствие.** Identity connected ≠ Calendar ready ≠ Gmail ready.

---

## ADR-072 — Google Calendar is live external source, no local event mirror

**Контекст.** Локальный cache календарных событий расходится с Google.

**Решение.** M18 читает/пишет Google Calendar live через tools. Нет таблицы `calendar_events`, нет sync cron, нет webhook.

**Следствие.** Google остаётся source of truth. Jarvis не зеркалирует встречи.

---

## ADR-073 — Calendar access only through GoogleCredentialService

**Контекст.** Tokens лежат в `credentials_encrypted`.

**Решение.** `GoogleCalendarService` получает access token только через `GoogleCredentialService::getValidAccessToken()`. Tools не делают Google HTTP и не читают credentials.

**Следствие.** Refresh, revoke и lock остаются в одном месте.

---

## ADR-074 — Incremental Google scopes

**Контекст.** M17 identity scopes недостаточны для Calendar; будущий Gmail не должен попасть в M17/M18 connect.

**Решение.** Default connect остаётся identity-only. Enable Calendar запрашивает Calendar scope incrementally (`include_granted_scopes`). Stored scopes = union existing + newly granted. Refresh token preservation из M17 сохраняется.

**Следствие.** Gmail scopes запрашиваются только через incremental `?intent=gmail` (M19).

---

## ADR-075 — External writes use ToolConfirmationPolicy

**Контекст.** Model может предложить создать/изменить встречу без явной команды.

**Решение.** External write + `explicitUserCommand=true` → allowed. Model-proposed / unknown → `confirmation_required`. Сигнал команды задаёт application layer, не model args.

**Следствие.** `authorized=true` в arguments игнорируется.

---

## ADR-076 — Destructive Calendar delete requires persisted confirmation

**Контекст.** M16 skeleton возвращал `confirmation_required` без возможности подтвердить позже.

**Решение.** `tool_confirmations` в DB (encrypted arguments, user+conversation, TTL, one-time). Conservative yes/cancel parser + Web/Telegram buttons. Model cannot invent the token or self-confirm.

**Следствие.** Delete usable across Telegram/Web/restart. Expired/cancelled cannot execute.

---

## ADR-077 — Client-generated Google event id for create idempotency

**Контекст.** Calendar `events.insert` не имеет generic idempotency key.

**Решение.** Core генерирует Google-compatible event id из user/conversation/tool-call id (`[a-v0-9]`). Retry того же ToolCall повторяет id. Model не задаёт ключ.

**Следствие.** AI/tool retry не создаёт duplicate meeting.

---

## ADR-078 — Reminder remains a separate subsystem

**Контекст.** «Напомни» легко спутать с Calendar event.

**Решение.** `create_reminder` остаётся Core Reminder Engine. Calendar tools не создают reminders и наоборот.

**Следствие.** Delivery и Google Calendar не смешиваются.

---

## ADR-079 — Gmail is live source of truth

**Контекст.** Локальный mailbox mirror расходится с Gmail.

**Решение.** M19 читает/пишет Gmail live через tools. Нет таблиц `emails` / `gmail_messages` / `gmail_threads`.

**Следствие.** Google остаётся source of truth. Jarvis не зеркалирует inbox.

---

## ADR-080 — Gmail access only through GoogleCredentialService

**Контекст.** Tokens лежат в `credentials_encrypted`.

**Решение.** `GoogleGmailService` получает access token только через `GoogleCredentialService::getValidAccessToken()`. Tools не делают Gmail HTTP и не читают credentials.

**Следствие.** Refresh, revoke и lock остаются в одном месте для Calendar и Gmail.

---

## ADR-081 — Incremental Gmail scopes

**Контекст.** Identity/Calendar scopes недостаточны для Gmail; полный `mail.google.com` избыточен.

**Решение.** Enable Gmail запрашивает `gmail.readonly` + `gmail.compose` + `gmail.modify` incrementally (`include_granted_scopes`). Stored scopes = union existing identity + Calendar + Gmail. Refresh token preservation из M17 сохраняется.

**Следствие.** Connected Google ≠ Gmail-enabled. Card показывает Identity / Calendar / Gmail отдельно.

---

## ADR-082 — Email send always requires persisted confirmation

**Контекст.** Send имеет внешний side effect и Gmail `messages.send` не даёт generic idempotency.

**Решение.** `send_gmail_message` всегда `confirmation_required` (`ToolMeta.alwaysConfirm`), даже при явной команде «отправь». One-time `tool_confirmations` execute блокирует повторную отправку. Preview: recipients + subject + bounded body.

**Следствие.** Duplicate confirm/cancel/expire не шлёт письмо. Cross-user confirm denied.

---

## ADR-083 — Gmail write HTTP calls are not auto-retried

**Контекст.** Retry `drafts.create` / `messages.send` / modify может дублировать действие.

**Решение.** GET/search/read могут ограниченно retry. Write HTTP retry = 0. Отдельная idempotency-таблица не вводится: send закрыт confirmation one-time; draft retry risk документирован.

**Следствие.** Нет слепого duplicate send из HTTP layer.

---

## ADR-084 — No Gmail mailbox mirror or polling

**Контекст.** Proactive inbox monitoring потребует watch/historyId и локальный store.

**Решение.** M19 = on-demand tools only. Нет cron, push, `users.watch`, History API.

**Следствие.** «Есть новые письма?» идёт через `list_gmail_messages` / `search_gmail` в conversation turn.

---

## ADR-085 — Draft ≠ send

**Контекст.** Черновик и отправка легко смешать в одном tool.

**Решение.** `create_gmail_draft` только создаёт draft. `send_gmail_message` только шлёт (после confirm). Reply = те же tools с `reply_to_message_id` / `thread_id` и корректными MIME headers. Отдельный reply tool не нужен.

**Следствие.** «Сделай черновик» не отправляет письмо. Attachments outbound и trash/delete отложены.

---

## ADR-086 — Admin Panel ≠ Personal Workspace

**Контекст.** Owner легко начинает «болтать» в админке.

**Решение.** Admin = техническое управление (users, AI, integrations, groups, diagnostics, logs, settings). Personal Workspace = общение с Jarvis. Owner не использует Admin как основной chat UI.

**Следствие.** Route is `/jarvis`. `/cabinet` remains User Space. Admin stays at `/dashboard`.

---

## ADR-087 — Conversation is the central UX entity

**Контекст.** Projects, mail, calendar, memory конкурируют за экран.

**Решение.** В Workspace центр — conversation (text + voice). Остальные панели вторичны.

**Следствие.** Нет inbox-first или calendar-first owner home.

---

## ADR-088 — Web / Desktop / Mobile share one Jarvis Core

**Контекст.** Нативные клиенты соблазняют локальным AI.

**Решение.** Все клиенты — adapters. Memory, tools, credentials, provider selection — только Core.

**Следствие.** [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md).

---

## ADR-089 — Voice is a mode, not a separate assistant

**Контекст.** Voice легко оформить как второй продукт.

**Решение.** Voice Mode на выбранном `conversation_id`. Нет отдельных voice memories и нет auto-created voice chat.

**Следствие.** Runtime ≠ Orb UI. Provider можно сменить без нового ассистента.

---

## ADR-090 — Same conversation continues across clients

**Контекст.** Telegram / Web / Desktop / Mobile иначе плодят треды.

**Решение.** Один `conversation_id` на все каналы одного space. New Chat — только явный выбор.

**Следствие.** History смешивается хронологически в одном catalog.

---

## ADR-091 — Desktop = Tauri 2 + React / TypeScript

**Контекст.** Нужен native desktop без второго Core.

**Решение.** Tauri 2, React, TS, Vite, Three.js/WebGL для Orb. Thin client.

**Следствие.** [CLIENTS/DESKTOP_APP.md](CLIENTS/DESKTOP_APP.md).

---

## ADR-092 — Mobile = Flutter

**Контекст.** iOS и Android должны делить один client codebase.

**Решение.** Flutter latest stable. Нет прямых Google API с устройства.

**Следствие.** [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md).

---

## ADR-093 — Desktop and Mobile are separate repositories

**Контекст.** Rust/Tauri и Flutter внутри Laravel repo засоряют CI, deploy и Cursor context.

**Решение.** `Owiiiii1/JARVIS` (Core + Admin + Cabinet + Workspace). `Owiiiii1/JARVIS-Desktop`. `Owiiiii1/JARVIS-Mobile`. Один логический продукт, разные toolchains и release cycles.

**Следствие.** Master protocol docs остаются в JARVIS. Production backend без Tauri/Flutter tree.

---

## ADR-094 — Orb visualization is provider-neutral

**Контекст.** Смена ElevenLabs/OpenAI/Gemini не должна переписывать UI.

**Решение.** Orb читает `VoiceVisualizationState` (state, amplitudes, bands, connection). Runtime мапит vendor audio в этот контракт.

**Следствие.** [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

---

## ADR-095 — GitHub goes through the Integration Framework

**Контекст.** «Посмотри commit» легко сделать в Telegram adapter или локальном git.

**Решение.** GitHub — owner-only provider + tools. Credentials в `integration_accounts`. Implemented in M21 (OAuth App MVP). Live validation deferred by Owner.

**Следствие.** Read + controlled write (issue/comment/branch/PR create). Не в Channel Layer. GitHub App installations may follow.

---

## ADR-096 — GitHub OAuth App MVP; GitHub App later

**Контекст.** Granular per-repo installation is better long-term but heavier.

**Решение.** M21 uses a GitHub OAuth App (`repo` + `read:org`). A GitHub App installation model may replace or extend this later.

**Следствие.** `repo` is broad; document why. No PAT in Admin.

---

## ADR-097 — GitHub is a live external source of truth

**Контекст.** Local commit mirrors go stale.

**Решение.** No `github_*` content tables. On-demand REST tools only.

**Следствие.** No webhook/polling in M21.

---

## ADR-098 — GitHub credentials only through encrypted IntegrationAccount

**Контекст.** PAT fields leak through UI and logs.

**Решение.** OAuth token envelope in `credentials_encrypted`. Tools use `GitHubCredentialService`. Envelope never serializes.

**Следствие.** Disconnect wipes local credentials even if remote revoke fails.

---

## ADR-099 — GitHub HTTP only through GitHubApiService

**Контекст.** Tool classes otherwise copy headers and leak tokens.

**Решение.** All REST calls live in `GitHubApiService`. Central Accept / API version / User-Agent / Authorization.

**Следствие.** Provider can change without rewriting each tool.

---

## ADR-100 — GitHub retrieval is explicit and tool-driven

**Контекст.** Private repos must not appear in every prompt.

**Решение.** No automatic GitHub context injection. No automatic memory ingestion.

**Следствие.** Owner must ask; Conversation AI calls tools.

---

## ADR-101 — No repository mirror and no shell git for integration

**Контекст.** `git clone` on the server is a different product.

**Решение.** GitHub API only. No clone/pull/log for conversational GitHub.

**Следствие.** Local repo operations remain out of scope.

---

## ADR-102 — M21 write surface is issue / comment / branch / PR create

**Контекст.** Full write access via `repo` is dangerous.

**Решение.** Implement only create issue, comment, create branch, create PR. Config `allowed_write_operations`.

**Следствие.** No merge, delete, force, file write, workflow edit, secrets.

---

## ADR-103 — No merge / delete / force operations in M21

**Контекст.** Merge and delete are hard to undo.

**Решение.** Do not register those tools. Branch create refuses overwrite.

**Следствие.** Risk stays limited even with `repo` scope.

---

## ADR-104 — GitHub writes use standard confirmation; not alwaysConfirm

**Контекст.** Send-mail is alwaysConfirm because it is externally visible and easy to spam.

**Решение.** GitHub issue/comment/branch/PR follow M16 external-write policy (explicit allowed, model-proposed confirmation_required). Not alwaysConfirm.

**Следствие.** No merge means residual risk is manageable.

---

## ADR-105 — M21 tests and live GitHub validation are deferred by Owner

**Контекст.** Production DB tests and live OAuth are high-risk without Owner smoke windows.

**Решение.** Implement M21 without `php artisan test` and without connecting a real GitHub account.

**Следствие.** Status: implemented, not live-validated. Combined Google smoke remains deferred.

---

## ADR-110 — `/jarvis` is Owner Personal Workspace

**Контекст.** Docs allowed `/workspace` or `/jarvis`.

**Решение.** Owner Personal Workspace route is `/jarvis`. Not `/workspace`. Not `/dashboard`. Not `/cabinet`.

**Следствие.** Route names live under the `jarvis.*` namespace.

---

## ADR-111 — Owner default landing is Workspace

**Контекст.** Owner login previously opened Admin.

**Решение.** Ordinary owner login redirects to `/jarvis`. `intended()` still honours an explicit Admin URL. Admin stays reachable from Workspace and via **Open Jarvis** from Admin.

**Следствие.** Admin is technical, not the default messenger.

---

## ADR-112 — Owner `/cabinet` redirects to `/jarvis`

**Контекст.** Owner could open the User Cabinet messenger.

**Решение.** Owner hitting `/cabinet` (and cabinet chat URLs) redirects to Workspace. `role=user` Cabinet is unchanged.

**Следствие.** One owner web messenger. No dual owner UIs.

---

## ADR-113 — Workspace uses the same `conversations` / `messages`

**Контекст.** Temptation to add `workspace_conversations`.

**Решение.** Workspace reads and writes the existing personal catalog. Telegram chats of the owner appear in `/jarvis`. New Chat is a normal personal conversation.

**Следствие.** No workspace-specific storage schema.

---

## ADR-114 — Workspace inbound channel remains `web`

**Контекст.** Branding could invent `channel=workspace`.

**Решение.** Channel names the transport. Workspace messages use `web` + `client_message_id` UUID, same as Cabinet.

**Следствие.** No new channel enum for M22.

---

## ADR-115 — Personal settings vs technical admin settings

**Контекст.** Owner needs General Prompt in the messenger without seeing API keys.

**Решение.** Workspace: General Prompt, timezone display, voice prefs later, integrations status + Admin deep link. Admin: providers, models, OAuth, workers, webhooks.

**Следствие.** Workspace must not reproduce OAuth forms or AI vendor config.

---

## ADR-116 — Voice Mode is a placeholder in M22

**Контекст.** Voice UI docs describe Orb + runtime.

**Решение.** M22 ships Text/Voice toggle, CSS Orb placeholder, and `VoiceModePlaceholder` / future `<VoiceSession conversationId>` boundary. No microphone, STT, TTS, WebRTC, ElevenLabs, or Three.js.

**Следствие.** M23 replaced the placeholder with `VoiceSession` without changing conversation identity. Historical for M22.

---

## ADR-118 — Vision images are current-turn payload, not replayed context blobs

**Контекст.** Multimodal turns must not resend every historical screenshot on every later message.

**Решение.** Only the current inbound message’s image bytes are converted to `AiContentPart` image parts. Previous attachments stay as text placeholders (`[N images attached]`) plus whatever the assistant already said. No attachment-specific memory ingestion.

**Следствие.** Explicit historical-image retrieval can be added later. Telegram/Desktop/Mobile can reuse `message_attachments` without changing this policy.

---

## ADR-119 — Copyable artifacts are distinct from fenced code

**Контекст.** Jarvis often returns prompts, configs, and drafts that should be copied in one click. Ordinary code fences are already used for illustrative snippets.

**Решение.** Provider-neutral markdown: ` ```artifact Title ` renders as an Artifact block with Copy of raw text. Language-tagged fences remain Code blocks with Copy. SafeMarkdown parses this in Core UI; adapters do not emit vendor-specific widgets.

**Следствие.** Models are instructed to use artifact fences only for copy-paste payloads.

---

## ADR-120 — Chat images are private and ownership-gated

**Контекст.** Screenshots must not get permanent public URLs.

**Решение.** Store on the private `local` disk under `chat-attachments/`. Preview/full routes require auth + conversation/message/attachment ownership. Clients receive only route URLs, never filesystem paths. Limits live in `config/chat_attachments.php`.

**Следствие.** Cabinet/Telegram/native clients can mount the same access service later.

---

## ADR-121 — OpenAI/Anthropic vision is not faked in M22.1

**Контекст.** Production conversation path is Gemini. OpenAI and Anthropic adapters are text-only.

**Решение.** `supportsVision()` is true only for Gemini. Other providers return `vision_not_supported` instead of dropping images.

**Следствие.** Adding vision to another adapter is an adapter change, not a Conversation Engine rewrite.

---

## ADR-122 — Telegram photo ingestion is later, same entity

**Контекст.** M22.1 is Web-first.

**Решение.** Do not ingest Telegram photos now. The `message_attachments` schema is not image-only and is not Web-only.

**Следствие.** A later Telegram adapter can persist the same rows and call the same Conversation Engine.

---

## ADR-123 — M22.1 tests and live vision are deferred by Owner

**Контекст.** Production DB tests and live Gemini vision are high-risk.

**Решение.** Implement M22.1 without `php artisan test` and without live AI vision / Google / GitHub.

**Следствие.** Status: implemented, not validated.

---

## ADR-124 — Screenshots are ephemeral media (default 24h)

**Контекст.** Chat images are for the current multimodal turn and a short follow-up window, not a photo library.

**Решение.** Default `retention_class=ephemeral`, `expires_at = created_at + config retention` (24 hours). Configurable. No “save screenshot forever” in M22.2.

**Следствие.** After expiry only a textual visual summary remains. Persistent images would be a future explicit action.

---

## ADR-125 — Image originals purge only after summary readiness, with hard fallback

**Контекст.** Purging before a summary would lose all visual memory of the screenshot.

**Решение.** Soft purge: `expires_at <= now` AND `summary_status=ready`. Failed summaries keep the original until `hard_retention_days` (7). After hard retention, purge bytes and leave metadata that the summary is unavailable. Bounded hourly command. No mass delete in migration.

**Следствие.** Historical M22.1 rows stay valid; scheduler performs lifecycle.

---

## ADR-126 — Screenshot summaries are derived attachment metadata, not personal memory

**Контекст.** The assistant reply (“yes, file permissions”) is not enough months later to know what was on the screenshot.

**Решение.** Dedicated `summary_text` / `summary_status` via `AttachmentVisionSummaryService`. Memory Engine may still extract durable user facts from normal conversation text. It must not bulk-ingest screenshot summaries or Storage contents.

**Следствие.** Historical context uses `[Previous screenshot summary: …]`.

---

## ADR-127 — Persistent user files live in Jarvis Storage

**Контекст.** Owner needs a personal file library for logs and source, separate from chat screenshots.

**Решение.** `stored_files` + `stored_file_chunks` + `/jarvis/storage`. Owner-only. Permanent until delete. Private disk. Text/source formats only in M22.2.

**Следствие.** PDF/Office/images-as-Storage are later extractors.

---

## ADR-128 — `message_attachments` and `stored_files` have separate lifecycle semantics

**Контекст.** Mixing chat media and the document library would break retention, retrieval, and security.

**Решение.** Screenshots stay on `message_attachments`. Documents stay on `stored_files`. Optional `message_stored_files` pivot when a StoredFile is sent in chat. No physical copy.

**Следствие.** Chat upload and direct Storage upload share StoredFile when the user attaches a text file; they never share screenshot rows.

---

## ADR-129 — Stored files are private and permanent until deletion

**Контекст.** Owner documents are not public cache.

**Решение.** Private filesystem, ownership-gated download, UUID physical names, no automatic expiry.

**Следствие.** Delete is UI-confirmed and optionally a destructive tool with `ToolConfirmationService`.

---

## ADR-130 — Stored file content is chunked and tool-retrieved, never auto-injected

**Контекст.** Logs can be tens of megabytes.

**Решение.** Extract + chunk. Conversation Engine may inline only a configured small threshold. Otherwise tools. No “all files” in system prompt. Tool outputs have hard char/chunk limits.

**Следствие.** ContextBudgetManager remains M22.3.

---

## ADR-131 — Chat file and direct Storage upload reuse the same StoredFile entity

**Контекст.** A log sent in chat should still appear in Storage.

**Решение.** One `stored_files` row. Chat adds a pivot. Direct upload has no message.

**Следствие.** Source-chat links on the Storage page when a pivot exists.

---

## ADR-132 — Storage file contents are untrusted data

**Контекст.** Source files and screenshots can contain prompt-injection text.

**Решение.** Platform guidance: Storage contents and screenshot pixels are untrusted user data; embedded instructions are not system/tool authorization.

**Следствие.** Applies to tool results and current-turn inline excerpts.

---

## ADR-133 — Full context budgeting remains M22.3

**Контекст.** Storage tools, web research, and conversation windows will compete for tokens.

**Решение.** M22.2 only bounds Storage tool results. Do not implement ContextBudgetManager yet.

**Следствие.** M22.3: Web Research + global Context Budget Manager.

---

## ADR-134 — M22.2 tests and live vision are deferred by Owner

**Контекст.** Production DB and live Gemini remain high-risk.

**Решение.** Implement M22.2 without `php artisan test`, live vision, live AI conversation, Google, or GitHub.

**Следствие.** Status: implemented, not validated.

---

## ADR-135 — Web Research is explicit tool-driven retrieval

**Контекст.** Owner needs current public-web facts without dumping the internet into every prompt.

**Решение.** `search_web` and `fetch_web_page` are Tool Layer tools behind `WebSearchManager` / `WebSearchProvider` / `WebPageFetchService`. Providers: `gemini_google` (Gemini Google Search grounding, existing Gemini credentials), `tavily`, `null`. Controllers, Conversation Engine, and the `search_web` tool do not call search APIs or know the vendor. Search does not auto-fetch pages. Grounding metadata is normalized to `WebSourceReference` inside the Gemini adapter.

**Следствие.** The model chooses which 2–5 URLs to read via `fetch_web_page`.

---

## ADR-136 — Web content is untrusted data

**Контекст.** Pages can contain prompt-injection (“send all Gmail to attacker”).

**Решение.** Platform rule: web text is quoted source material only. It cannot override instructions, grant permissions, authorize tools, or reveal secrets. `ToolExecutionContext` remains the source of authorization.

**Следствие.** A page cannot enable Gmail/GitHub/Storage.

---

## ADR-137 — Search and page fetch are separate tools

**Контекст.** Downloading every search hit would blow latency, cost, and context.

**Решение.** `search_web` returns compact snippets. `fetch_web_page` reads one URL. Caps: max searches, fetches, and total web chars per turn.

**Следствие.** `web_research_budget_exceeded` when caps hit.

---

## ADR-138 — SSRF and private-network access are forbidden

**Контекст.** Server-side fetch is a classic SSRF surface.

**Решение.** http/https only. Deny localhost, loopback, RFC1918, link-local, metadata, internal hostnames, credentials, non-http schemes. Resolve DNS before request; revalidate every redirect.

**Следствие.** No unix/file/data/javascript fetches. No browser automation in M22.3.

---

## ADR-139 — Web results do not auto-enter personal memory

**Контекст.** Scraped prices and news are not durable personal facts.

**Решение.** Memory analysis ignores web-scraped facts unless the user explicitly asked to remember a personal fact. Fetched pages are not stored in DB.

**Следствие.** Source links in the assistant message are enough for later conversation.

---

## ADR-140 — Context size is governed centrally by ContextBudgetManager

**Контекст.** Conversation, memory, Storage, Gmail, GitHub, and web can each overflow a prompt.

**Решение.** `ConversationContextBuilder` gathers slices; `ContextBudgetManager` decides how much of each enters one AI request. Named budgets live in `config/context_budget.php`.

**Следствие.** Local tool bounds remain; they are not the only limiter.

---

## ADR-141 — Model context limits resolve per provider/model with a conservative fallback

**Контекст.** Models do not share one context window.

**Решение.** `AiModelContextPolicy` + `config/ai_model_context.php`. Unknown model → conservative default (32k context, 2k output reserve). Input budget = max − reserve − safety margin.

**Следствие.** Do not hardcode one window in Conversation Engine.

---

## ADR-142 — Token estimator is intentionally conservative

**Контекст.** Perfect tokenizers are provider-specific.

**Решение.** `TokenEstimator` overestimates via Unicode-aware chars/words + overhead. Prefer overestimating. Provider usage can later show drift in logs.

**Следствие.** Before each provider call, estimated input must be ≤ input budget.

---

## ADR-143 — Recent history raw window is token-bounded

**Контекст.** A message-count window still explodes if messages are huge.

**Решение.** Take newest messages backwards until the recent-token budget is exhausted. Preserve complete message boundaries. Count limits remain query bounds only.

**Следствие.** Emergency minimum recent context is kept; old memories are dropped first.

---

## ADR-144 — Old history is summary-first / raw-on-demand

**Контекст.** Lifetime chats cannot be stuffed into the prompt.

**Решение.** Keep current architecture: current `conversation_summary` for older same-chat; cross-chat summaries; raw other chats only via `search_conversation_history`.

**Следствие.** DB size does not imply prompt size.

---

## ADR-145 — Global ToolResult budget is a second safety layer

**Контекст.** A GitHub diff, Gmail thread, web page, or Storage log can overflow even when base context is small.

**Решение.** `ToolResultBudgetManager` trims every ToolResult. Preserve structure; trim content first. Shared per-turn tool budget. Exhausted → `tool_context_budget_exceeded`.

**Следствие.** Gmail/GitHub/Web/Storage cannot independently overflow context.

---

## ADR-146 — Compaction never deletes raw conversation

**Контекст.** Summaries are derived.

**Решение.** `UpdateConversationSummaryJob` still writes versions. Raw `messages` stay. Coverage uses existing `from_message_id` / `to_message_id`.

**Следствие.** No M22.3 destructive data migration.

---

## ADR-147 — Summaries update incrementally and stay bounded

**Контекст.** Re-summarizing a lifetime chat every turn would be unbounded.

**Решение.** Previous summary + unsummarized range (capped). Refresh on message **or** token threshold. Cap summary chars and recompress if needed.

**Следствие.** Unsummarized queries use LIMIT. No all-history summarizer prompt.

---

## ADR-148 — Database size must not imply prompt size

**Контекст.** Owner scale goal: 1M messages still yields a bounded one-turn request.

**Решение.** After summary threshold, extra raw rows do not materially grow the normal prompt. Only retrieval/index cost may grow. Persistent files stay on disk; object storage is a future threshold, not this milestone.

**Следствие.** Context diagnostics log metrics only, never private texts.

---

## ADR-149 — M22.3 tests and live web/AI are deferred by Owner

**Контекст.** Production DB and live Tavily/AI remain high-risk.

**Решение.** Implement M22.3 without `php artisan test`, live search, live fetch, live AI conversation, Google, or GitHub.

**Следствие.** Status: implemented, not validated.

---

## ADR-150 — Web Research provider is Admin-configurable infrastructure

**Контекст.** `.env` alone is not operable for Owner. Workspace is a chat surface, not a technical console.

**Решение.** Provider, enablement, fetch toggle, and bounded limits live in Admin → Settings → Integrations → Web Research (`web_research_settings`). Runtime reads `WebResearchSettingsService` only.

**Следствие.** `/jarvis` is not the editor. Workspace may show read-only `Web Search · Google|Tavily|Disabled`.

---

## ADR-151 — Provider selection is not a per-conversation preference

**Контекст.** Mixing Gemini grounding and Tavily per chat would fork tool semantics and credentials.

**Решение.** One instance-level provider: `gemini_google` | `tavily` | `disabled`. No conversation-level override.

**Следствие.** Changing provider in Admin applies to the next Owner Conversation turn.

---

## ADR-152 — Gemini Google Search is a WebSearchProvider, not Conversation Engine logic

**Контекст.** Gemini Google Search grounding is vendor-specific (request shape, grounding metadata).

**Решение.** `GeminiGoogleSearchProvider` implements `WebSearchProvider`. Conversation Engine, `SearchWebTool`, and Gemini chat `chat()` stay vendor-neutral. No Google payload in the conversation client.

**Следствие.** Search discovery can switch to Tavily/disabled without rewriting the engine.

---

## ADR-153 — Gemini Search reuses the existing Gemini credential

**Контекст.** Duplicating the Gemini API key into web research settings would split secret lifecycle.

**Решение.** `GeminiGoogleSearchProvider` reads `ai_provider_settings` where `provider=gemini` (`is_connected` + encrypted `api_key`). Admin shows configured yes/no only. No Gemini key field on the Web Research card.

**Следствие.** Disconnecting Gemini in AI settings makes Google Search “not configured”.

---

## ADR-154 — Tavily remains an independent fallback provider

**Контекст.** Tavily is not Google Search and must remain selectable.

**Решение.** Keep `TavilyWebSearchProvider`. Tavily key is a separate encrypted credential (`web_research_settings.tavily_api_key`) with env `WEB_SEARCH_API_KEY` fallback. Do not delete Tavily.

**Следствие.** Admin can choose Tavily without using Gemini grounding.

---

## ADR-155 — fetch_web_page remains vendor-neutral server fetch

**Контекст.** Gemini grounding is discovery, not a safe page reader. SSRF policy is ours.

**Решение.** `fetch_web_page` always uses `WebPageFetchService` + `WebUrlGuard`, never Gemini grounding or Tavily extract.

**Следствие.** Fetch limits/timeout come from effective Admin settings; SSRF rules stay immutable.

---

## ADR-156 — Admin-configurable limits are bounded by immutable safety ceilings

**Контекст.** An Admin typo must not disable context or SSRF safety.

**Решение.** `effective = min(admin_setting, hard_safety_ceiling)` with floors. Ceilings live in `config/web_research.php`. `TurnBudgetTracker` and fetch/search use effective values. `ContextBudgetManager` remains the final prompt safety layer.

**Следствие.** Admin cannot raise searches/fetches/chars/timeout above code ceilings.

---

## ADR-157 — SSRF and security policy cannot be disabled in Admin

**Контекст.** Server-side fetch is an SSRF surface. Prompt injection on pages is mandatory to treat as untrusted.

**Решение.** No Admin switches for private IP, localhost, schemes, redirect validation, or prompt-injection protection.

**Следствие.** Those controls stay code/config only.

---

## ADR-158 — Secrets never round-trip to the frontend

**Контекст.** Inertia payloads are visible in the browser.

**Решение.** Never return Gemini or Tavily plaintext keys. Tavily UI is set/replace + configured/not configured (+ optional clear). Gemini has no key input on this card.

**Следствие.** Masked/configured status only.

---

## ADR-159 — Tests and live search remain deferred

**Контекст.** M22.3.1 adds Admin settings and Gemini provider wiring on production.

**Решение.** No `php artisan test`, no PHPUnit, no live Google Search, no live Tavily, no live fetch, no live AI. Status from configuration presence only. No Test Connection button.

**Следствие.** Implemented / not validated. Later milestone may add live smoke.

---

## ADR-160 — Owner manual production validation (vision, Storage, Gemini web search)

**Контекст.** M22.1–M22.3.1 shipped as implemented / not validated. Automated tests were not run. Owner later used production Workspace and confirmed specific functions.

**Решение.** Record **MANUAL PASS** only for: Workspace image upload; Gemini vision recognition; persistent text-file upload; persistent Storage retrieval/read; Gemini Google Search web research (current information). Admin Gemini Google Search configuration is PASS only insofar as that working search path required it. Do not mark whole milestones validated. Do not mark `fetch_web_page`, Tavily, SSRF, ContextBudgetManager, screenshot purge/summarization, Storage library UI, destructive delete, artifact copy, Google Calendar/Gmail combined smoke, or GitHub runtime as PASS.

**Следствие.** Automated tests remain not run. Status vocabulary includes MANUAL PASS vs IMPLEMENTED / NOT VALIDATED.

---

## ADR-161 — Voice is a modality over an existing conversation

**Контекст.** Voice легко оформить как второго ассистента.

**Решение.** Audio → STT → ordinary user turn → `ConversationTurnService` → TTS. Session always has `user_id` + existing `conversation_id`. Text ↔ Voice must not create a new conversation.

**Следствие.** No second Jarvis / User Space / voice chat.

---

## ADR-162 — Voice transcripts are ordinary messages

**Контекст.** Temptation to store `voice_messages`.

**Решение.** Final STT text and assistant text are normal `messages` rows. Modality lives in metadata (`modality=voice`, `voice_session_public_id`). Channel `web` vs modality `voice` stay distinct. `MessageType::Voice` remains Telegram inbound voice notes.

**Следствие.** No `voice_messages` / `voice_history` tables.

---

## ADR-163 — No voice-specific memory

**Контекст.** Long spoken sessions look like a separate memory problem.

**Решение.** One Memory Engine. No `voice_memory`.

**Следствие.** Voice cannot fork Owner/User memory.

---

## ADR-164 — STT/TTS are provider-neutral ports

**Контекст.** Vendor SDKs leak into Core.

**Решение.** `SpeechToTextProvider` / `TextToSpeechProvider` + managers. Runtime does not instantiate vendor clients. Null providers return `voice_stt_not_configured` / `voice_tts_not_configured`. Optional `RealtimeDuplexSpeechProvider` is future-only; STT and TTS remain canonical.

**Следствие.** ElevenLabs/Whisper can change without rewriting Conversation Engine.

---

## ADR-165 — Voice Runtime and Voice UI are separate

**Контекст.** Orb work can swallow the runtime.

**Решение.** M23 = runtime + CSS client boundary. M24 = final Orb. UI consumes session state/events; it does not own STT/TTS.

**Следствие.** Three.js is not required to ship Voice Runtime.

---

## ADR-166 — Conversation AI config is unchanged by Voice providers

**Контекст.** Selecting ElevenLabs or Whisper could silently retarget Owner Conversation AI.

**Решение.** Voice STT/TTS settings are Integrations technical settings. Owner Conversation AI stays `ai_role_settings` / `ai_provider_settings` for chat.

**Следствие.** Whisper may reuse an OpenAI key only for `/audio/transcriptions`, never `chat()`.

---

## ADR-167 — Interrupting playback does not delete the persisted assistant message

**Контекст.** Barge-in might tempt a delete of “unspoken” text.

**Решение.** If assistant text is already persisted, keep it. Optional `voice_playback_interrupted=true`.

**Следствие.** History is what Jarvis intended to say, not only what was heard.

---

## ADR-168 — Raw audio is ephemeral by default

**Контекст.** Storing recordings forever is a privacy/storage trap.

**Решение.** Temp private files → STT → delete on success; short retry window on failure; `jarvis:voice:cleanup-temp`. Source of truth is the transcript. Optional archive is a later product decision. No `raw_audio_archive` table.

**Следствие.** Cleanup never deletes messages.

---

## ADR-169 — Long voice sessions use the same ContextBudgetManager

**Контекст.** Hours of transcripts could dump into one prompt.

**Решение.** Voice messages are ordinary messages, so summary-first budget applies. No separate voice context window.

**Следствие.** A 5-hour session must not create a 5-hour prompt.

---

## ADR-170 — Web / desktop / mobile share VoiceRuntimeService

**Контекст.** Web controller could become the Core.

**Решение.** HTTP Workspace controller is an adapter. Desktop/Mobile later call the same runtime via Client API.

**Следствие.** Do not duplicate STT/turn/TTS in clients.

---

## ADR-171 — Telephony is a future adapter, not M23

**Контекст.** Twilio/SIP looks like “voice”.

**Решение.** No Twilio Voice, SIP, PSTN, phone routing, or call recording in M23. Phone is a later adapter over Voice Runtime.

**Следствие.** M23 is Workspace/session voice, not a call center.

---

## ADR-172 — Final Orb is M24

**Контекст.** Visual Orb can consume the milestone.

**Решение.** M23: state/event contract + CSS orb. M24: Three.js/WebGL Orb.

**Следствие.** Do not ship shaders to claim Voice Runtime.

---

## ADR-173 — Tests and live voice validation remain deferred

**Контекст.** Production Owner policy: no `php artisan test`, no live STT/TTS/AI during this work.

**Решение.** Static/build/migrate/route/schedule verification only. Do not claim live voice PASS.

**Следствие.** Status is IMPLEMENTED / NOT VALIDATED until Owner exercises Voice.

---

## ADR-174 — Initial STT is Whisper-or-Null; Gemini STT is M23.1

**Контекст.** Gemini `generateContent` with audio would contaminate Conversation AI.

**Решение.** M23 ships the STT port, Null, and an OpenAI Whisper adapter on the dedicated transcriptions API. Default STT is `none`. Do not hack Gemini chat for transcription. A dedicated Gemini STT adapter, if needed, is M23.1.

**Следствие.** Unconfigured STT is a safe `voice_stt_not_configured`, not a fatal crash.

---

## ADR-175 — Orb is provider-neutral

**Контекст.** A vendor SDK in the renderer would lock Voice UI.

**Решение.** `JarvisVoiceOrb` consumes only `VoiceVisualizationState`. No ElevenLabs/STT/Conversation AI fields.

**Следствие.** Speech providers can change without rewriting shaders.

---

## ADR-176 — Runtime state and audio analysis are separate

**Контекст.** Backend session status is not microphone energy.

**Решение.** `voice_sessions.status` maps to visualization `state`. Amplitudes and bands come from local `VoiceAudioAnalyzer` (or demo synthetic output energy).

**Следствие.** Listening can look alive without STT. Thinking does not fake a waveform.

---

## ADR-177 — Input analyser is local browser-only

**Контекст.** Visualization needs mic energy before providers exist.

**Решение.** Web Audio `AnalyserNode` after Start Voice. No upload, no archive, no backend requirement for demo visualization.

**Следствие.** M23 temp-audio policy is unchanged.

---

## ADR-178 — Orb does not own microphone or session lifecycle

**Контекст.** Putting getUserMedia inside the renderer couples UI to auth/HTTP.

**Решение.** `VoiceSession` owns permission, tracks, session HTTP. The Orb only renders.

**Следствие.** Desktop can reuse the engine with a different session client.

---

## ADR-179 — Three.js visualization engine has no Laravel dependency

**Контекст.** Desktop is Tauri + React, not Inertia.

**Решение.** Engine lives in `resources/js/voice/visualization` without Inertia, Ziggy, or Blade.

**Следствие.** Flutter does not reuse Three.js; it keeps the same state contract.

---

## ADR-180 — Mock/demo mode exists until providers are connected

**Контекст.** Owner needs to judge the Orb without live STT/TTS.

**Решение.** `?voice_demo=1` or `VITE_VOICE_DEMO_MODE`. Hidden drawer cycles states. Synthetic speaking energy is labeled demo, not fake TTS.

**Следствие.** Do not show the drawer in normal Voice chrome.

---

## ADR-181 — No OrbitControls in product Voice UI

**Контекст.** Dragging the sphere turns it into a toy.

**Решение.** Fixed cinematic framing. Subtle procedural camera drift only.

**Следствие.** No user orbit/pan/zoom of the Orb.

---

## ADR-182 — WebGL fallback is required

**Контекст.** Some browsers/devices have no WebGL.

**Решение.** Polished CSS orb + readable state text. Controls still work.

**Следствие.** Missing WebGL is not a Voice Mode crash.

---

## ADR-183 — M24 does not change voice memory or runtime semantics

**Контекст.** A visual milestone could tempt a second voice history.

**Решение.** No new tables. Same conversation. Same `VoiceRuntimeService`. Transcripts remain ordinary messages.

**Следствие.** M24 is frontend visual/product layer only.

---

## ADR-184 — Owner and User share one Personal Workspace product

**Контекст.** Cabinet was a simpler chat. Product decision: ordinary users get the same chat product.

**Решение.** One Shared Personal Workspace frontend and `PersonalChatSurfaceService`. Role/capabilities gate features. No second Conversation Engine.

**Следствие.** Do not fork composer, message renderer, or Voice UI.

---

## ADR-185 — Role and capabilities change features, not chat implementation

**Решение.** Owner chrome (projects, integrations, Admin, Storage page) is additive. User sees the same thread/composer/Orb without those panels.

---

## ADR-186 — One login form for Owner and User

**Решение.** Email + password. No self-registration. Accounts created only by Owner via Admin → Users.

---

## ADR-187 — Login landing `/jarvis` vs `/chat`

**Решение.** Owner → `/jarvis`. User → `/chat`. Owner `/chat` → `/jarvis`. User `/jarvis` → `/chat`.

---

## ADR-188 — User self-registration forbidden

**Решение.** No register route/page. Access code remains Telegram pairing only, not a web password.

---

## ADR-189 — User accounts only Owner-created

**Решение.** Admin Users catalog is the only account factory.

---

## ADR-190 — Users get instance Web Research by default

**Решение.** Capability `web_research` is in the default user set. Provider/keys stay Admin. Users do not configure Web Research.

---

## ADR-191 — Users get image and file chat

**Решение.** Same attachment and Storage-upload path as Owner, scoped to `user_id`. Vision uses Default User Conversation AI, never Owner AI as fallback.

---

## ADR-192 — Persistent user files are private by user_id

**Решение.** `StoredFile` queries always include authenticated `user_id`. No cross-user public_id access.

---

## ADR-193 — No Storage page for users

**Решение.** Files in chat + AI retrieval tools. `/jarvis/storage` remains owner-only. No `/chat/storage`.

---

## ADR-194 — Users get Voice Runtime and Orb

**Решение.** Capability `voice`. Same `VoiceRuntimeService`, `VoiceSession`, `JarvisVoiceOrb`. Routes `/chat/.../voice` alias the same controller as `/jarvis/.../voice`. Session `user_id` must match.

---

## ADR-195 — Users have no Projects

**Решение.** No Projects UI/routes. `get_project_context` stays capability `projects` (owner). Backend deny, not only hidden UI.

---

## ADR-196 — Users have no configurable integrations

**Решение.** No Google/Gmail/GitHub/ElevenLabs/Web Research settings for users. Telegram pairing remains a channel mechanism.

---

## ADR-197 — Users have own memory and General Prompt

**Решение.** Existing memory engine + `user_ai_settings.general_prompt`. Retrieval scoped by `user_id`. Workspace settings drawer edits the user’s own prompt.

---

## ADR-198 — Default User Conversation AI stays separate from Owner AI

**Решение.** `AiConfigurationResolver::resolveConversation` still picks Owner vs User role configs. No fallback to Owner AI.

---

## ADR-199 — `/cabinet` is a compatibility redirect

**Решение.** Canonical user URL is `/chat`. `/cabinet` and `/cabinet/chats/{id}` redirect. Cabinet is not a separate product.

---

## ADR-200 — Frontend capability props are presentation only

**Решение.** Inertia `capabilities` hide chrome. Authorization remains `User::canUseCapability` + ownership queries.

---

## Открытые решения (`TBD`)

- Алфавит generated access_code (кроме зарезервированного 2000).
- 403 vs redirect когда user открывает admin URL.
- Auth схема Desktop/Mobile (token flavour).
- Realtime native voice transport (WebRTC vs WebSocket streaming) beyond M23 HTTP utterance blobs.
- Concrete production STT beyond Whisper-or-Null (M23.1 if Gemini/other).
- Optional long-term audio recording retention.
- Набор service updates (`my_chat_member`) beyond bot left/kicked/member/admin/restricted.
- Retention raw messages по закону/желанию пользователя (отдельно от derived lifecycle).
- UX явного переноса group knowledge → personal fact.
- Persisted capability overrides (сейчас достаточно default из role).
- Retention `tool_execution_logs`.
