# Этапы разработки

Четыре фазы. Каждая опирается на предыдущую. Storage разговоров из Phase 1 остаётся source of truth и в Phase 2–4: меняется способ **выборки** контекста, а не формат сырых сообщений.

Общие правила:

- Phase 1 должна быть простой и рабочей.
- Контракты ядра (spaces, capabilities, users, isolation by `user_id`, conversation kind, message, telegram_groups, channel adapter, три AI configs, prompt hierarchy) закладываются сразу.
- То, что рано фиксировать (конкретная Vector DB, очередь, точные URL API) — `TBD`.

---

## Phase 1 — Telegram AI Assistant

Первый рабочий MVP.

### Цель

Owner (и затем users) общаются с Jarvis в Telegram **после pairing**. История не теряется. Chat Selector переключает active conversation. Исполнение: вехи 1–6 (MVP + selector), users/reminders — 7–10 в [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

### Функциональность

- Telegram Bot — первый communication channel, не ядро.
- Pairing: `/start` + `access_code` (owner `2000`). Нет auto-create User. Нет AI до pairing.
- Авторизованный user/owner пишет **личные** DM.
- Channel adapter нормализует входящее и передаёт в Jarvis Core.
- Core сохраняет raw message.
- Conversation AI **пространства** получает recent **текущего** chat (+ later summaries других чатов того же space).
- AI отвечает в DM.
- Ответ сохраняется и уходит в тот же канал.
- Параллельно: бот в группах → авторегистрация, persist всех увиденных сообщений, админ-чат, исходящие через adapter, **без автоответа**.

Допустима простая память: история в БД, в модель уходит последние N сообщений **текущего** conversation + platform prompt + User General Prompt этого user (если задан).

Архитектура уже должна позволять позже заменить «последние N» на интеллектуальный retrieval, **не меняя** таблицу messages и канал. Схема допускает **много** conversations на user. New Chat не создаёт новую долговременную память. ADR-017.

Один `owner` + много `user`. Owner pairing кодом `2000`, не hardcoded `user_id`. Обычный user не видит Admin Panel.

### Основные компоненты

- Jarvis Core: users, conversations (direct + group, много на user), messages, configuration
- Channel Layer: Telegram adapter (DM и group updates)
- Модуль Telegram Groups: discovery, persist, Group Messaging Service
- AI Layer: Owner Conversation / Owner Analysis / Default User Conversation + `resolveConversationAI(user)`
- Admin Panel: Conversation AI, Analysis AI, Telegram bot, Telegram Groups; слот Users / User Card
- Контракт Cabinet (тот же engine); UI кабинета — инкремент после persist, см. ниже

### Memory в Phase 1

- Обязательно: raw conversation history в БД.
- Опционально / заготовка: user profile, platform system prompt, слот User General Prompt.
- Не требуется: topics, embeddings, vector search, automatic memory extraction.

Интерфейсы `ContextBuilder` и `MemoryRetriever` могут существовать в упрощённой реализации (`RecentMessagesRetriever`).

### Definition of done

- `/start` отвечает; неверный код не вызывает AI; верный код линкует identity.
- Сообщение **paired** Telegram проходит: adapter → core → persist → Conversation AI → persist → adapter.
- После рестарта процесса контекст читается из БД.
- Новый Telegram-апдейт не создаёт «чистый» чат без истории того же пользователя.
- AI-логики нет в Telegram-specific коде.
- Админка задаёт **три** AI config, плюс token бота. User DM не зовёт Owner Conversation / Analysis.
- Неизвестная группа после первого update появляется в админке без ручного ID.
- Сообщения группы пишутся в raw history; Jarvis в группу сам не пишет, кроме явной отправки из админки.
- Personal persist и retrieval принимают `user_id`; нет глобального «контекста инстанса» в prompt.

### Не входит

- Structured topics и fact extraction (в т.ч. глубокий group analysis)
- Автоответы и сложные group policies
- Смешение group history с personal memory
- Смешение context разных users
- Обязательный полный Cabinet UI в первом Telegram-срезе (контракт — да)
- Mobile / Desktop / Voice
- Tools/actions как продукт
- Ручное редактирование памяти в админке
- Realtime streaming голоса

---

## Users & Cabinet — вехи 6–10

Не ждать Phase 4. Не делать второй backend. Owner pairing и Telegram MVP — вехи 1–5; Chat Selector — веха 6; Cabinet / User Telegram / Reminders — 7–10.

### Цель

Дополнительные пользователи и Web Cabinet на том же Core. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

### Функциональность

- Admin: Users, User Card, Chats (read), Topics, AI Settings, Open Cabinet (impersonation).
- Cabinet: Chat (несколько независимых conversations) + Profile.
- User General Prompt на все чаты этого user.
- Default User Conversation AI; User General Prompt правит сам user; optional later override.
- Auth: admin context ≠ cabinet context; ownership на каждом запросе.

Memory в этом инкременте может оставаться recent-window + user prompt. Phase 2 наполняет topics/memories **уже per-user**.

### Definition of done

- User A не получает messages/memories/topics user B.
- New Chat: пустой raw, память user не обнуляется.
- Админ открывает Cabinet без пароля пользователя.
- Обычный user не видит admin panel и чужие ресурсы.

---

## Phase 2 — Structured Long-Term Memory

Главная архитектурная задача проекта.

### Проблема

Постоянное общение даёт огромную историю: проекты, быт, люди, идеи, планы, техника, предпочтения, события, параллельные темы.

Передавать всё модели каждый раз:

- дорого и медленно;
- плохо масштабируется;
- засоряет prompt window;
- снижает качество ответа.

### Цель

Jarvis имеет большую долговременную память, но в модель попадает только **релевантный** пакет контекста.

### Базовый принцип

Каждое новое сообщение анализируется:

- к какой теме относится;
- это существующая тема или новая;
- относится ли сразу к нескольким темам;
- есть ли факт, который стоит сохранить;
- меняет ли он ранее известное;
- информация временная или долговременная.

Пример: вопрос про проект `Jarvis` может подтянуть тему Jarvis **этого** user. Не подтягивать его бытовые темы и **никогда** темы/факты другого user.

### Подход

См. [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md).

Кратко:

- Raw messages остаются нетронутыми.
- Появляются topics, memories, summaries, relations, revisions.
- Перед LLM собирается context package.
- Classification и extraction — отдельные шаги pipeline, не обязательно один LLM-вызов.
- Relational DB — основной store. Embeddings / vector search — когда понадобятся (`TBD` момент внедрения).

### Основные компоненты

- Memory engine: topics, memories, summaries, retrieval, extraction — **owner/scope обязателен**
- Расширенный Context Builder (hierarchy + только scoped retrieval)
- Owner Analysis AI закрывает classification / extraction / summarization / group jobs (позже вложенные слоты, не меняя бизнес-логику)
- Group knowledge с provenance ≠ personal memory; personal memory ≠ другой user
- Админка: User Topics / диагностика памяти, не полноценный ручной редактор на старте фазы

### Prerequisites

- Стабильный Phase 1: persist messages с `user_id`, Telegram adapter, AI provider abstraction
- Та же схема conversations/messages

### Definition of done

- В prompt не уходит вся история.
- Есть topics и derived memories, связанные с raw messages.
- Смена модели не уничтожает raw history.
- Можно пересчитать derived memory с исходных сообщений.
- Telegram DM, Cabinet и будущие личные каналы используют тот же **personal** memory engine **этого** `user_id`.
- Анализ групп использует Analysis AI и пишет в group knowledge, не в user profile и не в чужой personal scope.

### Не входит

- Обязательная Vector DB
- Полноценный knowledge graph как отдельный продукт
- Автоответ во все группы
- Mobile / Desktop / Voice как обязательный scope
- Human-like turn-taking Phase 4

---

## Phase 3 — Workspace, native clients, Voice

### Цель

Owner общается в Personal Workspace (не в Admin). Тот же Jarvis на Desktop и Mobile, включая Voice Mode. Память и история общие **внутри user**. Репозитории клиентов отдельные.

### Функциональность

- Combined Google smoke (validation)
- GitHub tools (owner)
- Owner Web Workspace в `Owiiiii1/JARVIS`
- Versioned Client API
- Voice Runtime + Orb UI (раздельно)
- Desktop: Tauri 2, `Owiiiii1/JARVIS-Desktop`
- Mobile: Flutter, `Owiiiii1/JARVIS-Mobile`

Клиенты **не** содержат собственной memory/AI-логики.

### Prerequisites

- Phase 1 контракты каналов
- Желательно Phase 2 retrieval; если Phase 3 начнётся раньше, клиенты всё равно бьют в то же ядро, а retrieval остаётся recent-window
- API-принципы: [API.md](API.md), голос: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md)

### Definition of done

- Одно и то же `conversation_id` / memory **данного user** видно из Telegram, Cabinet, Workspace и приложений.
- Голосовая реплика пишет в те же messages.
- Смена канала не создаёт второго «мозга».

### Не входит

- Полный human-like barge-in и initiative Phase 4 (закладываются интерфейсы, качество — следующая фаза)
- Отдельный голосовой ассистент с своей памятью
- Богатый продукт вокруг мобильного приложения (ленты, соцфункции и т.п.)

---

## Phase 4 — Human-like conversational system

### Цель

Переход от `question → answer` к `continuous personal assistant`.

Это отдельный conversational intelligence layer, не только новый prompt. Подробно: [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md).

### Направления

- latency и turn-taking;
- interruptibility / barge-in;
- short-term и long-term memory вместе;
- тон, личность, initiative, уточнения;
- понимание неполных фраз и отсылок («это», «тот проект», «как вчера»);
- смена темы и возврат к старой;
- реакция на паузы в голосе.

### Prerequisites

- Работающий retrieval (Phase 2)
- Voice path через тот же engine (Phase 3)
- Стабильный platform prompt, User General Prompt и user profile

### Definition of done

- Пользователь может говорить неполными фразами, опираясь на общий контекст.
- Перебивание голоса останавливает текущий TTS (`TBD` точный протокол).
- Jarvis может уточнять и проявлять инициативу в рамках настроек, а не только отвечать на явный вопрос.

### Не входит в обязательный scope фазы как «с нуля»

- Переписывание storage
- Привязка к одному speech/LLM vendor

---

## Переход Phase 1 → Phase 2 без переписывания

| Phase 1 уже есть | Phase 2 добавляет |
| --- | --- |
| `users`, `channel_identities`, `user_ai_settings` | без ломки; isolation уже есть |
| `conversations`, `messages` | те же таблицы; много chats на user; `kind=direct|group`; плюс classification links |
| `telegram_groups` + persist | analysis jobs, group knowledge + provenance |
| recent-window retriever | selective retriever за тем же интерфейсом |
| Owner / Default User Conversation AI на DM | Owner Analysis AI на jobs (группы, extract, summarize) |
| system prompt выбранного conversation config + User General Prompt | плюс analysis prompt + memory package с provenance и `user_id` |

Raw messages не удаляются при появлении summary.
