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

## Открытые решения (`TBD`)

- Алфавит generated access_code (кроме зарезервированного 2000).
- 403 vs redirect когда user открывает admin URL.
- Есть ли у owner отдельный cabinet UI или «мои чаты» в админке.
- Auth схема mobile/desktop.
- Realtime транспорт voice/text streaming; STT/TTS/interruption — практические тесты.
- Набор service updates (`my_chat_member`) beyond bot left/kicked/member/admin/restricted.
- Retention raw messages по закону/желанию пользователя (отдельно от derived lifecycle).
- UX явного переноса group knowledge → personal fact.
- Persisted capability overrides (сейчас достаточно default из role).
- Retention `tool_execution_logs`.
