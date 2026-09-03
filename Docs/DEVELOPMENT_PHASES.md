# Этапы разработки

Четыре фазы. Каждая опирается на предыдущую. Storage разговоров из Phase 1 остаётся source of truth и в Phase 2–4: меняется способ **выборки** контекста, а не формат сырых сообщений.

Общие правила:

- Phase 1 должна быть простой и рабочей.
- Контракты ядра (user, conversation kind, message, telegram_groups, channel adapter, AI roles) закладываются сразу.
- То, что рано фиксировать (конкретная Vector DB, очередь, точные URL API) — `TBD`.

---

## Phase 1 — Telegram AI Assistant

Первый рабочий MVP.

### Цель

Пользователь постоянно общается с Jarvis в Telegram. История не теряется после перезапуска сервера и не зависит от «сессии» мессенджера.

### Функциональность

- Telegram Bot — первый communication channel, не ядро.
- Пользователь пишет **личные** сообщения (DM).
- Channel adapter нормализует входящее и передаёт в Jarvis Core.
- Core сохраняет raw message.
- **Conversation AI** получает ограниченный объём предыдущего личного общения (recent window).
- AI отвечает в DM.
- Ответ сохраняется и уходит в тот же канал.
- Параллельно: бот в группах → авторегистрация, persist всех увиденных сообщений, админ-чат, исходящие через adapter, **без автоответа**.

Допустима простая память: история в БД, в модель уходит последние N сообщений текущего conversation (и при необходимости короткий system prompt + user profile-заготовка).

Архитектура уже должна позволять позже заменить «последние N» на интеллектуальный retrieval, **не меняя** таблицу messages и канал.

### Основные компоненты

- Jarvis Core: users, conversations (direct + group), messages, configuration
- Channel Layer: Telegram adapter (DM и group updates)
- Модуль Telegram Groups: discovery, persist, Group Messaging Service
- AI Layer: role-based config — минимум `conversation` и `analysis` (analysis в Phase 1 может быть только настроена)
- Admin Panel: Conversation AI, Analysis AI, Telegram bot, Telegram Groups list/chat, просмотр personal conversations

### Memory в Phase 1

- Обязательно: raw conversation history в БД.
- Опционально / заготовка: пустой или ручной user profile, глобальный system prompt.
- Не требуется: topics, embeddings, vector search, automatic memory extraction.

Интерфейсы `ContextBuilder` и `MemoryRetriever` могут существовать в упрощённой реализации (`RecentMessagesRetriever`).

### Definition of done

- Сообщение из Telegram проходит: adapter → core → persist → LLM → persist → adapter.
- После рестарта процесса контекст читается из БД.
- Новый Telegram-апдейт не создаёт «чистый» чат без истории того же пользователя.
- AI-логики нет в Telegram-specific коде.
- Админка задаёт **отдельно** Conversation AI и Analysis AI, плюс token бота.
- Неизвестная группа после первого update появляется в админке без ручного ID.
- Сообщения группы пишутся в raw history; Jarvis в группу сам не пишет, кроме явной отправки из админки.

### Не входит

- Structured topics и fact extraction (в т.ч. глубокий group analysis)
- Автоответы и сложные group policies
- Смешение group history с personal memory
- Mobile / Desktop / Voice
- Tools/actions как продукт
- Ручное редактирование памяти в админке
- Realtime streaming голоса

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

Пример: вопрос про проект `Jarvis` может подтянуть тему Jarvis, связанные решения и последние задачи. Не подтягивать разговоры про автомобиль, путешествия и прочую техническую работу.

### Подход

См. [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md).

Кратко:

- Raw messages остаются нетронутыми.
- Появляются topics, memories, summaries, relations, revisions.
- Перед LLM собирается context package.
- Classification и extraction — отдельные шаги pipeline, не обязательно один LLM-вызов.
- Relational DB — основной store. Embeddings / vector search — когда понадобятся (`TBD` момент внедрения).

### Основные компоненты

- Memory engine: topics, memories, summaries, retrieval, extraction
- Расширенный Context Builder
- Отдельные AI roles: `analysis` закрывает classification / extraction / summarization на старте фазы (позже можно выделить вложенные роли, не меняя бизнес-логику)
- Group knowledge с provenance ≠ personal memory
- Админка: позже диагностика памяти/topics/group knowledge, не полноценный ручной редактор на старте фазы

### Prerequisites

- Стабильный Phase 1: persist messages, один user, Telegram adapter, AI provider abstraction
- Та же схема conversations/messages

### Definition of done

- В prompt не уходит вся история.
- Есть topics и derived memories, связанные с raw messages.
- Смена модели не уничтожает raw history.
- Можно пересчитать derived memory с исходных сообщений.
- Telegram DM и будущие личные каналы используют тот же **personal** memory engine.
- Анализ групп использует Analysis AI и пишет в group knowledge, не в user profile.

### Не входит

- Обязательная Vector DB
- Полноценный knowledge graph как отдельный продукт
- Автоответ во все группы
- Mobile / Desktop / Voice как обязательный scope
- Human-like turn-taking Phase 4

---

## Phase 3 — Mobile, Desktop, Voice

### Цель

Один Jarvis доступен из Telegram, мобильного и desktop-клиента, включая голосовое общение. Память и история общие.

### Функциональность

- API для клиентов (auth, conversations, messages, позднее streaming/voice)
- Mobile: вход, текстовый чат, история, голос, статус
- Desktop: то же + быстрый доступ; background/tray — `TBD`
- Voice: STT → тот же conversation engine → TTS; целевой режим — realtime, не «запись файла → долгое ожидание»
- Speech provider (предпочтительно ElevenLabs) за abstraction layer

Клиенты **не** содержат собственной memory/AI-логики.

### Prerequisites

- Phase 1 контракты каналов
- Желательно Phase 2 retrieval; если Phase 3 начнётся раньше, клиенты всё равно бьют в то же ядро, а retrieval остаётся recent-window
- API-принципы: [API.md](API.md), голос: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md)

### Definition of done

- Одно и то же conversation/memory видно из Telegram и приложений.
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
- Стабильный system prompt и user profile

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
| `users`, `channel_identities` | без ломки |
| `conversations`, `messages` | те же таблицы; `kind=direct|group`; плюс classification links |
| `telegram_groups` + persist | analysis jobs, group knowledge + provenance |
| recent-window retriever | selective retriever за тем же интерфейсом |
| Conversation AI на DM | Analysis AI на jobs (группы, extract, summarize) |
| system prompt conversation | плюс analysis prompt + memory package с provenance |

Raw messages не удаляются при появлении summary.
