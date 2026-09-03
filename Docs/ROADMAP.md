# Дорожная карта

Четыре фазы. Каждая даёт работающий инкремент. Не делать Phase 4 внутри Phase 1.

Связанные тексты: [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md).

---

## Phase 1 — Telegram + persistent conversations

**Результат.** Пользователь постоянно общается с Jarvis в Telegram DM; история не теряется. Группы автоматически появляются в админке. Схема и Core уже multi-user: isolation, много chats, prompt/override слоты.

### Goals

- Первый канал: Telegram adapter (DM + groups).
- Raw messages и conversations в БД (`kind=direct|group`, `user_id` на personal).
- Личный ответ через **Conversation AI** (`resolve(role, user_id)`).
- Telegram Groups: discovery, persist, админ-чат, outbound через adapter, passive monitoring.
- Админка: platform Conversation / Analysis AI; Telegram bot; Telegram Groups.
- Контракты Users / Cabinet (UI кабинета — следующий инкремент).

### Основные компоненты

- Core (users, identities, conversations, messages, telegram_groups, user_ai_settings слот)
- Telegram adapter
- Groups module + Group Messaging Service
- Recent-window retriever **per user + conversation**
- Role-based AI: `conversation` + `analysis` + inheritance
- Admin config

### Prerequisites

- Работающий backend и админка окружения
- Telegram bot token
- Выбранные реализации для ролей (могут совпасть физически; конфиги раздельные)

### Definition of done

См. Phase 1 в [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md). Коротко: рестарт не обнуляет личный контекст; AI не в Telegram-коде; группа без ручного ID; Jarvis в группу сам не пишет.

### Не входит

- Topics / extraction / vector search / глубокий group analysis
- Автоответы и сложные group policies
- Смешение group history с personal memory или context разных users
- Обязательный полный Cabinet в первом Telegram-срезе
- Mobile, Desktop, Voice
- Tools как продукт
- Human-like barge-in

---

## Users & Cabinet (после persist Phase 1)

**Результат.** Несколько изолированных пользователей; Web Cabinet как ChatGPT; админ видит User Card.

### Goals

- Admin Users / User Card / Chats (read) / Topics / AI Settings / impersonated Open Cabinet
- Cabinet: список чатов, New Chat, Profile
- User General Prompt; platform default → user override
- Ownership на всех user endpoints

См. [USERS_AND_CABINET.md](USERS_AND_CABINET.md). Не ждать Phase 4.

---

## Phase 2 — Structured Memory

**Результат.** У Jarvis большая долговременная память, в модель уходит только релевантное.

### Goals

- Topics, memories, summaries, revisions
- Selective context package (не вся личная история и не вся лента группы)
- Пересчёт derived слоя с raw
- Classifier / extractor как отдельные шаги (**Analysis AI**)
- Group knowledge с provenance ≠ personal memory

### Основные компоненты

- Memory engine (personal per `user_id` + group + optional global)
- Расширенный context builder
- Роль `analysis` на jobs; позже вложенные роли без смены бизнес-логики
- Диагностика памяти и group knowledge в админке (базовая)

### Prerequisites

- Phase 1 persist, adapter, telegram_groups
- Те же таблицы messages (direct + group)

### Definition of done

Вся история не пихается в prompt. Факты трассируются к raw. Смена модели не уничтожает архив. Group analysis не пишет в user profile.

### Не входит

- Обязательная Vector DB
- Mobile/Desktop/Voice как must
- Полноценный ручной редактор графа памяти

---

## Phase 3 — Mobile + Desktop + Voice

**Результат.** Один Jarvis в Telegram, мобильном и desktop-клиенте, включая realtime voice.

### Goals

- Public API для клиентов
- Тонкие приложения без своей AI-логики
- Voice I/O через abstraction (ElevenLabs по умолчанию)
- Те же accounts, conversations и **личная** memory каждого user

### Основные компоненты

- API (auth, conversations, messages, voice sessions)
- Mobile client
- Desktop client
- STT / TTS / turn detection (минимально достаточный realtime)

### Prerequisites

- Стабильный engine Phase 1
- Желателен retrieval Phase 2; не блокировать API, если память ещё recent-window
- Auth: те же cabinet accounts; realtime transport `TBD`

### Definition of done

Смена клиента не меняет «личность» и историю **этого** user. Голосовая реплика пишется в его messages.

### Не входит

- Полный Phase 4 initiative/barge-in качества
- Отдельный голосовой продукт
- Overbuilt mobile ecosystem

---

## Phase 4 — Human-like conversational system

**Результат.** Общение естественное и непрерывное: не только пары вопрос–ответ.

### Goals

- Latency, turn-taking, interruptibility
- References и неполные фразы
- Initiative и clarification в рамках настроек
- Тон/личность стабильны на всех каналах

### Основные компоненты

- Conversational intelligence layer
- Улучшенный working context
- Voice barge-in доведённый до продукта
- Политики пауз и смены темы

### Prerequisites

- Phase 2 retrieval
- Phase 3 клиенты и голос
- Зрелый system prompt / profile

### Definition of done

См. [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md). Пользователь не пересказывает вчерашний день с нуля.

### Не входит

- Переписывание storage
- Привязка к одному LLM или speech vendor

---

## Принцип приоритизации

1. Сначала простая рабочая система (Phase 1) с multi-user контрактами.
2. Users / Cabinet — когда нужны дополнительные люди, сразу после persist.
3. Затем memory intelligence (Phase 2), уже per-user.
4. Затем native клиенты (Phase 3) на тех же accounts.
5. Затем естественность (Phase 4).

Не over-engineer Phase 1. Не принимать решений, которые ломают этот порядок (например, AI внутри Telegram handler).
