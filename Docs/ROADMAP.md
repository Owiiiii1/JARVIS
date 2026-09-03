# Дорожная карта

Четыре фазы. Каждая даёт работающий инкремент. Не делать Phase 4 внутри Phase 1.

Связанные тексты: [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md).

---

## Phase 1 — Telegram + persistent conversations

**Результат.** Пользователь постоянно общается с Jarvis в Telegram DM; история не теряется. Группы автоматически появляются в админке и пишутся в raw history без автоответов.

### Goals

- Первый канал: Telegram adapter (DM + groups).
- Raw messages и conversations в БД (`kind=direct|group`).
- Личный ответ через **Conversation AI**.
- Telegram Groups: discovery, persist, админ-чат, outbound через adapter, passive monitoring.
- Админка: отдельно Conversation AI и Analysis AI; Telegram bot; Telegram Groups; просмотр личных чатов.

### Основные компоненты

- Core (users, identities, conversations, messages, telegram_groups)
- Telegram adapter
- Groups module + Group Messaging Service
- Recent-window retriever (personal)
- Role-based AI: `conversation` + `analysis` в конфиге
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
- Смешение group history с personal memory
- Mobile, Desktop, Voice
- Tools как продукт
- Human-like barge-in

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

- Memory engine (personal + group scopes)
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
- Те же conversations/memory

### Основные компоненты

- API (auth, conversations, messages, voice sessions)
- Mobile client
- Desktop client
- STT / TTS / turn detection (минимально достаточный realtime)

### Prerequisites

- Стабильный engine Phase 1
- Желателен retrieval Phase 2; не блокировать API, если память ещё recent-window
- Решения `TBD`: auth, realtime transport

### Definition of done

Смена клиента не меняет «личность» и историю. Голосовая реплика пишется в те же messages.

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

1. Сначала простая рабочая система (Phase 1).
2. Затем memory intelligence (Phase 2).
3. Затем поверхность доступа (Phase 3).
4. Затем естественность (Phase 4).

Не over-engineer Phase 1. Не принимать решений, которые ломают этот порядок (например, AI внутри Telegram handler).
