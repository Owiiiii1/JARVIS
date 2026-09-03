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

Информация, сгруппированная вокруг темы (`Jarvis`, `машина`, `отпуск`). Тема живёт дольше одного разговора. Сообщение может относиться к нескольким темам.

### 3. Facts / long-term memories

Утверждения с **scope** и **provenance**. Personal: «предпочитает тёмную тему». Group knowledge: «в группе X решили сменить API». У факта есть confidence, время, source_kind, ссылки на raw messages (и группу), возможность revision.

Group knowledge не копируется в personal / user profile автоматически.

### 4. User profile

Стабильная информация о пользователе и предпочтениях. Меняется редко. Всегда кандидат в context package, но компактный.

### 5. Working context

Контекст **текущего** разговора: последние реплики, активная тема, незакрытый clarification. Короткоживущий. Не заменяет long-term facts.

### 6. Summaries

Сжатое представление длинных кусков диалога. Служебный слой для retrieval и для людей в диагностике. Не заменяет raw.

---

## Сущности (концептуально)

Имена таблиц могут отличаться; важны роли. Детали связей: [DATABASE.md](DATABASE.md).

- **conversations** — логический диалог (`direct` | `group`); не равен «Telegram chat id» навечно, но group conversation связан с `telegram_groups`.
- **messages** — raw history (личный и групповой в одной таблице).
- **telegram_groups** / **telegram_group_participants** — discovery групп и участники.
- **topics** — устойчивые темы.
- **message_topic_relations** — many-to-many, с весом/confidence классификации.
- **memories** — извлечённые факты/заметки с **provenance** и областью (`personal` / `group_knowledge` / …).
- **memory_topics** — привязка фактов к темам.
- **entities** — люди, проекты, места, вещи (`TBD` глубина в Phase 2 vs later).
- **entity_relations** — связи («проект X принадлежит клиенту Y»), без обязательного graph DB.
- **summaries** — сжатия диапазонов messages или topics.
- **user_profile** — стабильный профиль (один на владельца на старте).
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

- **scope** — `personal` | `group_knowledge` (иные — later)
- **source_kind** — `direct_conversation` | `telegram_group` | `summary` | `manual_admin` | иное
- **source_group_id** — если источник группа
- **created_at / updated_at**
- **source_message_ids** (откуда взяли)
- **confidence** (классификатор/экстрактор не уверен на 100%)
- **relevance score** на этапе retrieval (зависит от запроса, не хранится как единственная истина)
- **status**: active / superseded / obsolete / disputed (`TBD` точный enum)
- **valid_from / valid_until** для временных фактов, если известно

Retrieval учитывает:

- совпадение topics;
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

1. system / general prompt **роли conversation** (из админки, централизованно);
2. глобальные настройки Jarvis;
3. текущий conversation / working context;
4. релевантные topics;
5. релевантные memories;
6. необходимые recent messages (не вся история);
7. user profile — если полезен.

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

**Phase 1:** recent window по `conversation_id` + timestamps. Достаточно для MVP.

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

## Области памяти: personal vs group

Telegram Group history **не** становится автоматически персональной памятью владельца. ADR-012.

| Область | Что хранит | Кто пишет | Кто читает в prompt |
| --- | --- | --- | --- |
| Personal conversation history | DM / mobile / desktop / voice raw | Conversation path | Recent / retrieval личного треда |
| Personal memory | факты о владельце | extraction с DM + явный перенос | Conversation package |
| Group conversation history | raw группы | Groups persist | Админ-чат; Analysis retrieval |
| Group knowledge | решения/задачи/саммари группы | **Analysis AI** | В Conversation package **только** если запрос про эту группу |

Пример: «в рабочей группе решили сменить API» → `group memory` с provenance на группу и сообщения. Это не `personal user fact`, пока владелец явно не закрепит иначе (`TBD` UX).

Analysis jobs используют роль `analysis`, не `conversation`.

---

## Единство личной памяти между каналами

Personal memory engine не зависит от канала. Один `user_id`. Telegram **DM**, mobile, desktop и voice пишут в personal messages и читают один personal retrieval.

Голосовая реплика после STT — обычный inbound text для **личной** памяти.

Групповые сообщения — тот же message store с `conversation.kind = group`, другая область retrieval.
