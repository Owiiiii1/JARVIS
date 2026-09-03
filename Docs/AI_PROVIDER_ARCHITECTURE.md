# AI Provider Architecture

Jarvis не зависит от одного HTTP API и одной модели. Business logic (память, каналы, пользователи, группы) не импортирует SDK конкретного vendor.

Не существует архитектуры «одна глобальная AI model на весь Jarvis». Конфигурация **ролевая**. ADR-007, ADR-013.

---

## Зачем abstraction

- смена OpenAI → Anthropic → Gemini (или mix) без переписывания engine;
- **разные** provider/model на общение и на анализ;
- админка задаёт **platform** mapping роль → provider / model / prompt / parameters;
- optional **per-user override** той же роли (пусто = default);
- тесты подменяют provider;
- секреты остаются в settings, не в коде адаптеров каналов.

В репозитории уже есть заготовки AI settings в админке (OpenAI, Anthropic, Gemini). Целевой слой — использовать их как **пул реализаций**. Активный выбор — на уровне роли, не «текущая единственная модель продукта».

---

## Логические роли

Роль — first-class сущность. Business logic вызывает `AI Layer.resolve(role)`, не vendor.

### Обязательный минимум

| Роль | Назначение | Где используется |
| --- | --- | --- |
| `conversation` | общение + tool calls | Telegram DM, Cabinet, Mobile, Desktop, voice; owner tools (Calendar/Gmail) |
| `analysis` | аналитика и фон | Telegram-группы, topic classification, summarization, extraction (decisions / tasks / facts), memory processing, оркестрация embeddings-related jobs |

Роли независимы:

- другой provider;
- другая model;
- другие parameters;
- отдельный prompt;
- отдельная ссылка на credentials.

Пример (не требование): Conversation = OpenAI / GPT-X; Analysis = другая OpenAI model, Claude, Gemini или более дешёвая модель.

Физически на MVP обе роли *могут* указывать на один и тот же vendor model id. Конфиги и промпты всё равно раздельные. Нельзя свести это к одному полю «active model».

### Будущие роли (слоты)

Добавляются **без** смены Conversation Engine / Groups / Memory business logic:

- `classification`
- `summarization`
- `embeddings`
- `memory_extraction`
- `voice_reasoning`

Пока слота нет, Analysis AI может закрывать несколько аналитических jobs. Выделение роли — смена mapping, не переписывание пайплайна.

| Задача | Кто закрывает сейчас | Phase |
| --- | --- | --- |
| Ответ любому user | `conversation` + optional user override | 1 |
| Group analysis / summaries / extract | `analysis` | конфиг 1; jobs 2 |
| Topic classification | `analysis`, позже `classification` | 2 |
| Memory extraction | `analysis`, позже `memory_extraction` | 2 |
| Embeddings | later `embeddings` | future, не обязательно |

---

## Platform AI Settings

Отдельная конфигурация ролей, не один экран «модель Jarvis». Это **defaults инстанса**.

### Conversation AI

- provider;
- model;
- credentials reference;
- platform system / general prompt;
- parameters (temperature и др. при необходимости).

### Analysis AI

- provider;
- model;
- credentials reference;
- analysis prompt;
- parameters.

Ядро читает эти записи в runtime. Inertia-страница только пишет settings. ADR-009.

Текущий код (один `is_active` на весь продукт) **противоречит** этой модели и подлежит замене в Milestone 4. [CURRENT_STATE.md](CURRENT_STATE.md).

## Per-user assignment

```
resolve(role, user_id):
  if user_ai_settings имеет override для роли → его provider/model/params
  else → platform default этой роли
```

Не обязан у каждого user быть свой provider. ADR-019.

User General Prompt — отдельное поле user, не замена platform Conversation prompt. ADR-018.

Админ смотрит/меняет user слой с User Card. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

---

## Контракт провайдера (концептуально)

Каждый chat-capable provider умеет примерно:

- принять messages/system + параметры;
- вернуть текст (и позже tool calls, `TBD`);
- вернуть usage/latency для логов;
- сигнализировать ошибки (rate limit, auth).

Embeddings-provider — отдельный узкий порт, когда понадобится.

Speech не входит в этот документ: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## Prompt management

Два слоя conversation-текста:

1. **Platform / System Prompt** роли — правила продукта, safety, изоляция. Один на инстанс для роли. Нельзя дать user его отменить.
2. **User General Prompt** — стиль и ограничения **этого** user; действует во всех его чатах и личных каналах.

Hierarchy в context package: platform → channel rules → user prompt → memory → topics → conversation history → current message. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

- Conversation platform prompt ≠ analysis prompt.
- Каналы не хранят свою копию «на всякий случай».
- Версии промпта желательны для debug (`TBD`).

Личность Phase 4: platform prompt + User General Prompt + user profile, не клиент и не analysis prompt.

ADR-009 уточняется ADR-013 и ADR-018.

---

## Выбор модели в runtime

Порядок:

1. Вызывающий слой указывает роль и `user_id` (для personal path).
2. AI Layer: user override или platform mapping.
3. Подставляются platform prompt + User General Prompt (conversation path).
4. Conversation Engine и Analysis jobs не знают vendor.

Fallback при недоступности провайдера — `TBD` (ошибка vs запасная модель **той же роли**). Не подменять молча Analysis моделью Conversation и наоборот, если это не явная настройка.

---

## Tools / function calling

Порт «модель может вернуть tool request» закладывается в abstraction. Реестр tools и исполнители — later. Каналы tools не исполняют.

---

## Что не фиксируется сейчас

- Конкретные model id.
- Обязательный embeddings vendor.
- Формат structured output.
- Мультимодальный вход (картинки в Telegram) — `TBD`, payload messages должен допускать вложения позже.
