# Conversation Engine

Жизненный цикл **личного** сообщения владельца. Один engine для Telegram DM, mobile, desktop и голоса после STT.

Входящие из Telegram-групп **не** проходят этот reply path: persist + passive monitoring. См. ветку ниже и [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Нормализованный вход

Channel adapter (или Voice layer) передаёт в Core структуру уровня:

- `channel` (`telegram` / `mobile` / `desktop`, `TBD` точный enum);
- `modality` (`text` / `voice`) — голос не отдельный ассистент и не отдельный канал-мозг;
- `external_identity` (telegram user id, app user id, …);
- `conversation_hint` (telegram chat id, client conversation uuid, или «продолжить active»);
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

1. **Сообщение приходит через channel adapter.**
2. **Определяется пользователь** — `channel_identities` → `users`. Если identity неизвестна: политика Phase 1 — `TBD` (whitelist владельца / автосоздание единственного owner).
3. **Сохраняется raw message** до вызова модели. Падение LLM не должно терять входящее.
4. **Определяется active conversation** — существующий тред или новый. Канал может подсказать, ядро решает.
5. **Анализируется intent/topic** — в Phase 1 может быть no-op или лёгкий heuristic; в Phase 2 — classifier. Не обязан быть тем же вызовом, что ответ.
6. **Выбираются связанные topics.**
7. **Memory engine достаёт релевантную память** — в Phase 1 recent messages.
8. **Context builder формирует prompt / context package.**
9. **Вызывается Conversation AI** через provider abstraction (роль `conversation`).
10. **Сохраняется ответ** как raw message роли assistant.
11. **Ответ отправляется в исходный канал.**
12. **Post-processing** (после или параллельно с отправкой).
13. **Извлекаются потенциальные personal memories** (Phase 2; роль `analysis` или позже `memory_extraction`).
14. **Обновляются topics / summaries / memory / revisions** в personal scope.

Порядок 12–14 не должен блокировать шаг 11, если это ухудшает latency. Архитектура разделяет sync и async пути.

---

## Synchronous path

Нужно, чтобы пользователь получил ответ:

- identify user;
- persist inbound;
- resolve conversation;
- retrieve **минимально достаточный** контекст;
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

## Голос

После STT голосовой сегмент входит в тот же engine как inbound text. После ответа TTS читает outbound text. Interruption не создаёт отдельную память: это тот же conversation. См. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## Tools / actions

Будущие tool calls встраиваются **внутрь** шага LLM / после него, в AI Layer, не в adapter. Phase 1 их не реализует; engine не должен предполагать, что единственный выход — чистый текст навсегда (поле structured actions в outbound — `TBD`).
