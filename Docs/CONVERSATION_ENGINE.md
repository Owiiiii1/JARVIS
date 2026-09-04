# Conversation Engine

Жизненный цикл личного сообщения в **User Space или Owner Space**. Один engine. Разные AI configurations и capabilities. Cross-space retrieval запрещён.

Входящие из Telegram-групп **не** проходят этот reply path: persist + passive monitoring. См. ветку ниже и [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Нормализованный вход

Channel adapter (или Voice layer) передаёт в Core структуру уровня:

- `channel` (`telegram` / `web` / `mobile` / `desktop`, `TBD` точный enum);
- `modality` (`text` / `voice`) — голос не отдельный ассистент, не отдельный канал-мозг и не новый `conversation_id`. M24.1 Voice is hands-free VAD over the same turn: blob STT → this engine → TTS. No push-to-talk.
- `external_identity` (telegram user id, app user id, …);
- `conversation_id` или hint: Telegram → `channel_identities.active_conversation_id`; Cabinet / Workspace / apps → открытый chat; тот же id на всех клиентах;
- `payload` (текст и/или current-turn image attachments; медиа refs в `message_attachments`);
- `occurred_at`;
- `channel_message_id` для идемпотентности.

Адаптер **не** вызывает LLM.

Web Cabinet / Workspace: `JarvisWorkspaceController` + `PersonalChatSurfaceService` → `ConversationTurnService`. Owner uses `/jarvis`, user uses `/chat`. Same controller methods. Integer conversation id is authorized with `ConversationService::ensureOwned` (`user_id`). Cross-user id/URL access returns 404. Disabled users cannot enter a turn. Telegram handler нормализует inbound и вызывает тот же `ConversationTurnService`, затем send. User Conversation AI is `AiConfigurationResolver::resolveConversation` → Default User Conversation AI; never Owner Conversation AI.

Дополнительно для Telegram: `chat_kind` (`direct` / `group`), `telegram_chat_id`, sender fields. Group inbound **не** запускает personal reply path. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Telegram до pairing (не Conversation Engine)

Системные ответы: `/start` без identity, неверный код. AI не вызывается. Неверный ввод не пишется как normal conversation.

После pairing: conversation **`Основной`**, active. AI greeting (M4) — application event в system prompt, ответ `role=assistant` в `Основной`. Paired `/start` повторно AI не вызывает.

Telegram menu (`/start`, «Чаты», «Новый чат», callback выбора) **не** пишется в semantic conversation raw. Technical `role=system` placeholders (в том числе старые M3 «Сообщение сохранено…») не входят в AI context.

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
        resolve active conversation того же user_id
        шаги ниже (Conversation Engine + space Conversation AI)
```

---

## Шаги (личный DM / mobile / desktop / voice)

1. **Сообщение приходит через channel adapter или Cabinet API.**
2. **Определяется пользователь** — session / `channel_identities` → `users`. Ownership проверяется здесь. Неизвестная Telegram identity **не** входит в этот AI path: pairing в адаптере ([USERS_AND_CABINET.md](USERS_AND_CABINET.md)). Нет auto-create User. Owner и user — один pipeline.
3. **Сохраняется raw message** до вызова модели. Падение LLM не должно терять входящее. `user_id` + `conversation_id` обязательны для personal.
4. **Conversation** — active / указанный id **этого** space. Чужой id отвергается.
5. Intent/topic — scope этого space (Phase 2).
6. Topics только этого space.
7. Context Builder MVP (M4): platform prompt выбранного conversation config + User General Prompt + recent semantic messages **текущего** conversation (лимит 5–40, default 30) + current inbound. Другие чаты / groups / projects / long-term memory **не** добавляются. Technical `role=system` не входят в dialogue. Summary-first retrieval — later.
8. Hierarchy: platform prompt **Owner Conversation AI или Default User Conversation AI** → channel rules → User General Prompt → (7) → message.
9. **Conversation AI этого space.** Owner Analysis AI на DM не вызывается. User никогда не получает Owner Conversation config.
10. **Сохраняется ответ** как raw message роли assistant в ту же conversation.
11. **Ответ отправляется** в исходный канал / cabinet stream.
12. **Post-processing** (после или параллельно с отправкой).
13. **Извлекаются потенциальные personal memories** этого user (Phase 2; Owner Analysis AI или позже слот `memory_extraction`).
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

Sync retrieve: current recent + summaries + compact memory. Тяжёлый raw-on-demand и group hierarchical analysis — tool/job, не обязательный sync dump.

Web Workspace may send multipart images and persistent text files with the user turn. Empty body is allowed when at least one image or Storage file is present. Screenshots are ephemeral `message_attachments`. Text files become `stored_files` (optionally linked via `message_stored_files`). Neither screenshot bytes nor Storage contents are personal memory. Telegram photo ingestion is not in M22.2; the same attachment rows can be created later.

Vision context: current inbound image bytes only, and only while not purged. Historical attachments become a short screenshot summary placeholder when ready (`[Previous screenshot summary: …]`), never replayed pixels. ADR-118 / ADR-124.

Current-turn Storage files contribute compact metadata + a bounded excerpt when small. Large files are tool-retrieved (`list_storage_files`, `search_storage_file_contents`, `read_storage_file_chunks`). Storage is never auto-injected into every prompt. Content from screenshots, Storage, and the web is untrusted data.

Owner Conversation AI may call `search_web` then `fetch_web_page` (capability `web_research`). Search does not auto-fetch pages. Web text cannot authorize tools. Cite only URLs returned by those tools. [WEB_RESEARCH.md](WEB_RESEARCH.md).

`ContextBudgetManager` trims one request before the provider call: platform and current turn stay; recent history is token-bounded; older history is summary-first; tool results share a global budget. [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md). Tool rounds are capped by `context_budget.max_tool_rounds` (default 8).

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

Групповой analysis — async **Owner Analysis AI** (M14). Results live in `telegram_group_knowledge`, not personal `memories`. Owner personal chat does **not** auto-mix group knowledge. M14 may expose bounded derived facts only through `get_project_context` when a group is attached to a project. M15 Owner Conversation AI calls `search_group_knowledge` explicitly; queued analysis never blocks the personal turn.

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

Пустая conversation того же space; становится active в Telegram, если создана оттуда. Raw пустой. Summaries других чатов и structured memory остаются. Не «сброс профиля». Не копировать raw старых чатов.

---

## Голос

Тот же User Space, тот же selected `conversation_id`, тот же Conversation Engine (`ConversationTurnService`), тот же AI config space, одна memory. Нет отдельных voice memories и нет auto-created voice chat.

`VoiceRuntimeService` → `ConversationTurnService` → `ConversationAiService`. Direct LLM from Voice Runtime is forbidden.

Final STT transcript = ordinary user `messages` row (`channel=web`, `metadata.modality=voice`, `voice_session_public_id`). Assistant reply = ordinary assistant row. Interrupt after persist does not delete the assistant message (`voice_playback_interrupted`).

Voice uses the same User General Prompt plus an optional bounded spoken-style presentation hint. Same tools and confirmation policy. Same `ContextBudgetManager`: long voice sessions cannot grow an unbounded prompt because transcripts are normal messages.

Runtime: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Orb UI: [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

---

## Tools / actions

Tool loop в одном turn: несколько последовательных calls. Не `one message = max one tool call`. Safety limit: **max 5 tool rounds**.

Реализовано в Core (`ConversationAiService`): AI → tool call(s) → `ToolRegistry` → `ToolExecutionService` (capability + confirmation policy + `tool_execution_logs`) → tool result(s) → AI → возможно ещё tools → final answer. Telegram, Cabinet, Workspace и native clients не знают, какой tool сработал.

Tools:

- `create_reminder` — Reminder Engine. [REMINDERS.md](REMINDERS.md).
- `search_conversation_history` — targeted raw-on-demand по **текущему** user.
- `get_project_context` — owner-only (`projects` capability). Derived project context including bounded ACTIVE group knowledge for attached groups, не raw dump. Не подмешивается в обычный prompt.
- `search_group_knowledge` — owner-only (`group_analysis`). Explicit group search only.
- Google Calendar tools — owner-only (`google_calendar`). Live Google is the source of truth. [INTEGRATIONS.md](INTEGRATIONS.md).
- Gmail tools — owner-only (`gmail`). Live Gmail is the source of truth; no local mailbox. Search/list/read/thread/labels/draft/send/modify. Send always requires persisted confirmation. [INTEGRATIONS.md](INTEGRATIONS.md).
- GitHub tools — owner-only (`github`). Live GitHub is the source of truth; no local repo mirror and no shell git. Read repos/commits/files/search/issues/PRs/CI; controlled write: issue/comment/branch/PR create. No merge/delete/force/file-write. [INTEGRATIONS.md](INTEGRATIONS.md).
- `confirm_tool_action` / `cancel_tool_action` — only while a pending confirmation exists; require a server-side yes/cancel signal.

Gemini — production provider с function calling (`functionDeclarations` / `functionCall` / `functionResponse`). OpenAI и Anthropic chat работают; tool-enabled request им **не** отправляется молча (`supportsTools=false`).

Current user local datetime и IANA timezone инжектятся в system context на каждом turn. Calendar naive times use the same owner timezone.

Confirmation: read-only без confirm. Core `create_reminder` остаётся allowed. External write + explicit user command = allowed except tools with `alwaysConfirm` (`send_gmail_message`). Model-proposed = confirmation_required. Destructive = always confirmation_required and is persisted in `tool_confirmations`. Conservative yes/cancel parser plus Web/Telegram buttons. Модель не может self-authorize. [INTEGRATIONS.md](INTEGRATIONS.md).

Reminders: Reminder Tool → Reminder Engine, не Calendar. [REMINDERS.md](REMINDERS.md).
