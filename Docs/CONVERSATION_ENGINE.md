# Conversation Engine

Жизненный цикл **личного** сообщения **конкретного** `user_id`. Один engine для Telegram DM, Web Cabinet, mobile, desktop и голоса после STT. Owner и дополнительные users проходят тот же путь.

Входящие из Telegram-групп **не** проходят этот reply path: persist + passive monitoring. См. ветку ниже и [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Нормализованный вход

Channel adapter (или Voice layer) передаёт в Core структуру уровня:

- `channel` (`telegram` / `mobile` / `desktop`, `TBD` точный enum);
- `modality` (`text` / `voice`) — голос не отдельный ассистент и не отдельный канал-мозг;
- `external_identity` (telegram user id, app user id, …);
- `conversation_hint` (telegram chat id, cabinet conversation id, или «продолжить active» / «new chat»);
- `payload` (текст; медиа — refs, `TBD`);
- `occurred_at`;
- `channel_message_id` для идемпотентности.

Адаптер **не** вызывает LLM.

Дополнительно для Telegram: `chat_kind` (`direct` / `group`), `telegram_chat_id`, sender fields. Group inbound **не** запускает personal reply path. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Ветка: личное сообщение vs группа

```
normalize
  → if group:
        discover/register telegram_group
        persist raw (group conversation)
        optional lightweight / async Analysis AI
        do not reply unless group policy says so
  → if direct:
        шаги ниже (Conversation Engine + Conversation AI)
```

---

## Шаги (личный DM / mobile / desktop / voice)

1. **Сообщение приходит через channel adapter или Cabinet API.**
2. **Определяется пользователь** — session / `channel_identities` → `users`. Ownership conversation проверяется здесь. Неизвестная Telegram identity: политика `TBD` (whitelist / привязка / отказ), не «один магический owner в Core».
3. **Сохраняется raw message** до вызова модели. Падение LLM не должно терять входящее. `user_id` + `conversation_id` обязательны для personal.
4. **Определяется conversation** — существующий тред этого user или новый (New Chat). Чужой id отвергается. ADR-021.
5. **Анализируется intent/topic** — в Phase 1 no-op/heuristic; в Phase 2 — classifier **в scope этого user**.
6. **Выбираются связанные topics только этого user.**
7. **Memory engine достаёт релевантную память только этого `user_id`.** Phase 1: recent **этого** conversation. Phase 2: + его long-term memories. Другие его чаты raw не подмешиваются. ADR-016, ADR-017.
8. **Context builder** собирает hierarchy: platform → channel rules → User General Prompt → memories/topics → history этого чата → message. ADR-018.
9. **Вызывается Conversation AI** через `resolve(role=conversation, user_id)` (platform default или user override). ADR-019.
10. **Сохраняется ответ** как raw message роли assistant в ту же conversation.
11. **Ответ отправляется** в исходный канал / cabinet stream.
12. **Post-processing** (после или параллельно с отправкой).
13. **Извлекаются потенциальные personal memories** этого user (Phase 2; роль `analysis` или позже `memory_extraction`).
14. **Обновляются topics / summaries / memory / revisions** в **его** personal scope.

Порядок 12–14 не должен блокировать шаг 11, если это ухудшает latency. Архитектура разделяет sync и async пути.

---

## Synchronous path

Нужно, чтобы пользователь получил ответ:

- identify user + authorize conversation;
- persist inbound;
- resolve conversation **этого** user (или создать пустую);
- retrieve **минимально достаточный** контекст в его scope;
- build package;
- Conversation AI;
- persist outbound;
- send to channel.

В Phase 1 retrieve = recent window.  
В Phase 2 retrieve = selective, но всё ещё sync-бюджет. Тяжёлый graph walk — не в критическом пути, либо жёсткий timeout + fallback на recent (`TBD` политика).

---

## Asynchronous / post-processing path

Можно сделать после ответа (очередь/worker, `TBD` технология):

- глубокая topic classification;
- memory extraction и contradiction handling;
- summarization;
- embeddings (когда появятся);
- debug/trace logs;
- пересчёт derived memory.

Ядро должно позволять подключить queue, не требуя её в Phase 1 (достаточно sync no-op или inline post-process).

Групповой analysis (summaries, decisions, tasks) — только async и только роль `analysis`. Не в sync-пути personal reply.

---

## Исходящее из админки в группу

Не путать с ответом Conversation Engine.

```
Admin Panel
  → Group Messaging Service
  → Telegram Adapter
  → Bot API
  → persist raw в group conversation
```

LLM здесь не участвует, если администратор просто пишет текст.

---

## Идемпотентность и сбои

- Повтор одного и того же `channel_message_id` не создаёт дубликат raw (уникальность в рамках канала).
- Если LLM упал после persist inbound — можно retry generation, не принимая сообщение заново из Telegram.
- Если send в канал упал после persist outbound — retry send, не генерировать второй ответ без политики (`TBD`: at-least-once vs exactly-once на доставку).

---

## New Chat

Создаётся пустая conversation того же `user_id`. Следующий inbound идёт по шагам выше: raw пустой, User General Prompt и long-term memory user остаются. Не вызывать «сброс профиля».

---

## Голос

После STT голосовой сегмент входит в тот же engine как inbound text. После ответа TTS читает outbound text. Interruption не создаёт отдельную память: это тот же conversation. См. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## Tools / actions

Будущие tool calls встраиваются **внутрь** шага LLM / после него, в AI Layer, не в adapter. Phase 1 их не реализует; engine не должен предполагать, что единственный выход — чистый текст навсегда (поле structured actions в outbound — `TBD`).
