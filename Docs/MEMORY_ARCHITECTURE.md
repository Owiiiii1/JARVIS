# Архитектура памяти

Ключевой документ проекта. Реализация полноценного memory engine — Phase 2. Контракты и raw storage закладываются в Phase 1.

---

## Почему нельзя кормить модель всей историей

Постоянный личный ассистент накапливает годы сообщений. Если каждый запрос склеивать в один prompt:

- стоимость и latency растут линейно;
- окно модели заполняется шумом;
- релевантные факты тонут в бытовых репликах;
- смена темы «заражает» ответ чужим контекстом;
- схема не масштабируется.

Поэтому raw history — **архив и source of truth**, а не постоянный prompt.

---

## Raw history vs derived memory

| Слой | Что это | Можно пересчитать? | Можно удалить автоматически? |
| --- | --- | --- | --- |
| Raw messages | Точный текст/метаданные входящих и исходящих | Нет (это факты канала) | **Нет** — не уничтожаются только потому, что появился summary или memory |
| Derived memory | topics, facts, summaries, classifications, embeddings | Да | Да, с возможностью rebuild |

Derived memory — производный слой. Это позволяет:

- переиндексировать память;
- чинить ошибки классификации;
- менять AI-модели;
- перестраивать memory engine без потери исходных данных.

**Принцип:** raw messages никогда не удаляются автоматически из-за появления summary или extracted memory. Политика retention (юридическая, ручная очистка) — отдельное решение, `TBD`, и не смешивается с lifecycle derived-слоя.

---

## Типы памяти

### 1. Raw conversation history

Полная история сообщений. Phase 1 уже обязана это хранить. Поля уровня: conversation (`kind` direct|group), channel, role, body, timestamps, внешние id канала, для групп — sender и thread/reply.

Личные DM и group raw лежат в одном message engine, но это **разные области retrieval**. Group history не есть personal memory.

### 2. Topic memory

Информация, сгруппированная вокруг темы. Тема живёт дольше одного разговора и **принадлежит owner scope** (user / group / global). Сообщение может относиться к нескольким темам того же scope.

Примеры user topics: Programming, Python, School. Набор user A не виден user B.

### 3. Facts / long-term memories

Утверждения с **scope**, **owner** и **provenance**. Personal user: «учит Python» — обязательно `user_id`. Group knowledge: «в группе X решили сменить API». Global/system knowledge — отдельный scope, не чужие personal facts.

У факта есть confidence, время, source_kind, ссылки на raw messages, возможность revision.

Group knowledge не копируется в personal автоматически. Personal user A не копируется в B.

### 4. User profile

Стабильная информация **конкретного** пользователя и предпочтения. Один профиль на `user_id`. Компактный кандидат в **его** context package.

### 5. Working context / conversation context

Контекст **текущего** чата: последние реплики, активная тема, незакрытый clarification. Короткоживущий. Не заменяет long-term facts и не переносится сырьём в другой conversation.

New Chat обнуляет только этот слой. User long-term memory остаётся. ADR-017.

### 6. Summaries

Сжатое представление длинных кусков диалога. Служебный слой для retrieval и для людей в диагностике. Не заменяет raw.

---

## Сущности (концептуально)

Имена таблиц могут отличаться; важны роли. Детали связей: [DATABASE.md](DATABASE.md).

- **conversations** — логический диалог (`direct` | `group`); не равен «Telegram chat id» навечно, но group conversation связан с `telegram_groups`.
- **messages** — raw history (личный и групповой в одной таблице).
- **telegram_groups** / **telegram_group_participants** — discovery групп и участники.
- **topics** — устойчивые темы с owner (`user` | `group` | `global`).
- **message_topic_relations** — many-to-many, с весом/confidence классификации.
- **memories** — факты с **owner/scope** (`personal` + `user_id` | `group_knowledge` | `global/system`) и provenance.
- **memory_topics** — привязка фактов к темам.
- **entities** — люди, проекты, места, вещи (`TBD` глубина в Phase 2 vs later).
- **entity_relations** — связи («проект X принадлежит клиенту Y»), без обязательного graph DB.
- **summaries** — сжатия диапазонов messages или topics.
- **user_profile** — стабильный профиль на каждого user.
- **memory_revisions** — история изменений факта (было → стало, причина, source messages).

---

## Анализ входящего сообщения

Система определяет:

- тему(ы): существующие / новая / несколько сразу;
- стоит ли сохранять новую информацию;
- меняет ли она известный факт;
- временная это информация или долговременная.

Примеры:

- «Напомни, как мы решили делать память в Jarvis» → topic `Jarvis` + architectural memories, не весь быт.
- «Завтра в 8 сервис» → временный факт с expiry, не вечный профиль.
- «На самом деле машину забрал» → revision/contradiction предыдущего факта.

Topic classification и memory extraction — **разные** шаги. Их можно делать разными моделями, синхронно или после ответа.

---

## Relevance, confidence, время

Каждая derived-запись несёт как минимум:

- **scope** — `personal` | `group_knowledge` | `global_system`
- **owner** — для `personal` обязателен `user_id`; для group — group id; global — без user
- **source_kind** — `direct_conversation` | `telegram_group` | `summary` | `manual_admin` | иное
- **source_group_id** — если источник группа
- **created_at / updated_at**
- **source_message_ids** (откуда взяли)
- **confidence** (классификатор/экстрактор не уверен на 100%)
- **relevance score** на этапе retrieval (зависит от запроса, не хранится как единственная истина)
- **status**: active / superseded / obsolete / disputed (`TBD` точный enum)
- **valid_from / valid_until** для временных фактов, если известно

Retrieval **сначала** фиксирует scope (`user_id` / group / global). Глобальный поиск «всех memories инстанса» запрещён. ADR-016.

Затем учитывает:

- совпадение topics того же owner;
- свежесть;
- confidence;
- явное supersede;
- working context текущего разговора.

---

## Updates, contradictions, obsolete

- Новый факт, совместимый со старым — update или дополнение + revision.
- Прямое противоречие — пометить старый `disputed`/`superseded`, не удалять raw и не удалять старую memory-строку молча: нужен revision trail.
- Устаревшее по времени (`valid_until`) — не скармливать как актуальное, оставить для истории.
- Низкий confidence — можно хранить, но не класть в prompt без необходимости.

Точные пороги confidence — `TBD` (настраиваемые, не зашитые навечно).

---

## Context package

Перед вызовом LLM собирается пакет примерно из:

1. Platform / System Prompt роли `conversation`;
2. Channel / System Rules;
3. **User General Prompt** этого user (не заменяет п.1);
4. релевантные **его** memories;
5. релевантные **его** topics;
6. summary / recent **текущего** conversation (не все чаты raw);
7. user profile этого user;
8. current message.

См. иерархию в [USERS_AND_CABINET.md](USERS_AND_CABINET.md). ADR-018.

Не отправлять всю БД. Не отправлять все summaries «на всякий случай».

В Phase 1 пакет = prompt + recent messages (+ пустой/ручной profile).  
В Phase 2 тот же объект пакета наполняется retrieval.

---

## Pipeline памяти

Личный inbound:

```
incoming personal message
  → persist raw
  → intent / topic analysis
  → personal memory retrieval
  → context assembly
  → Conversation AI
  → response
  → persist response
  → post-processing
  → memory extraction / update (personal scope)
  → persistence of derived layer
```

Групповой inbound не идёт в этот reply-цикл: persist → optional Analysis AI → group knowledge. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

Шаги analysis/extraction после ответа могут быть асинхронными. См. [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md).

---

## Retrieval: сейчас и потом

**Phase 1:** recent window по `conversation_id` **и** `user_id` + timestamps. Достаточно для MVP. Другие чаты того же user в raw window не подмешиваются.

**Phase 2:** topic + keyword/SQL filters + freshness + profile. Relational DB как основной store.

**Future (не обязательно для MVP):**

- embeddings на messages / memories / summaries;
- semantic retrieval;
- hybrid search (SQL + vector);
- graph-like обход relations между topics/entities.

Отдельная Vector DB **не** обязательная зависимость. ADR-005. Момент введения — `TBD`, когда relational retrieval перестанет справляться с качеством.

---

## Summarization

- Запускается, когда разговор или тема превышают порог (`TBD`).
- Пишет в `summaries`, ссылается на диапазон message id.
- Raw не трогает.
- Summary можно пересобрать другой моделью.

---

## Области памяти

Telegram Group history **не** становится автоматически персональной памятью. ADR-012. Personal user A **не** становится контекстом user B. ADR-016.

| Область | Owner | Кто пишет | Кто читает в prompt |
| --- | --- | --- | --- |
| Personal conversation history | `user_id` + `conversation_id` | Conversation path | Только этот чат |
| Personal memory (owner и каждый user) | тот же `user_id` | extraction | Только его чаты |
| Group conversation history | group | Groups persist | Owner admin; Analysis |
| Group knowledge | group | Analysis AI | Owner analysis; не personal prompt обычного user |
| Global / system knowledge | instance | admin / system | Явно |

Пример: «в рабочей группе решили сменить API» → group knowledge. «Ребёнок учит Python» → personal memory этого ребёнка, не владельца и не сиблинга.

Analysis jobs используют роль `analysis`, не `conversation`.

---

## Единство личной памяти между чатами и каналами

Personal memory принадлежит `user_id`, не conversation и не каналу.

Telegram DM, Cabinet, mobile, desktop и voice этого user пишут в его messages (разные conversations) и читают **его** retrieval.

Голосовая реплика после STT — обычный inbound text для **его** памяти.

Несколько чатов одного user делят long-term memory. Сырой history другого чата в prompt не копируется. ADR-017.

Групповые сообщения — тот же message store с `conversation.kind = group`, другая область retrieval.
