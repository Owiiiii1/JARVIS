# Implementation plan

Исполняемые вехи. Не путать с уже работающим кодом: [CURRENT_STATE.md](CURRENT_STATE.md).

Порядок фиксирован. Не начинать Google/Voice до Identity + Conversation + AI configs. Reminders — до Google. Chat Selector — вместе с Telegram/User conversation, не optional later.

Исходная база: Laravel 13, Inertia/React, custom-admin-kit v0.5.0, Nutgram 4.50.0, webhook ACK-only, один admin login для всех `users`, CRM leftover, AI = listModels + one `is_active`.

---

## Milestone 0 — Cleanup / Baseline

**Статус.** COMPLETED (2026-09-03).

**Цель.** Чистый host без CRM-демо, рабочие тесты, Admin Kit и Telegram settings не сломаны.

**Реализуем**

- Удалить host CRM: models/controllers/pages `customers`, `services`, `staff`, `orders`.
- Migration: drop пустые `customers`, `services`, `staff`, `orders`, `order_staff` (после проверки 0 rows).
- Оставить kit, login, settings AI/Telegram, calendar placeholder, logs placeholder.
- Расширить baseline tests (login page, health, settings auth).
- Проверить webhook route и наличие Nutgram (не писать полный pairing).

`/start` **не** в этой вехе. Diagnostic `/start` — Milestone 2.

**Migrations:** одна drop-CRM.

**Backend:** удаление классов; `composer`/`route:list` без CRM.

**Frontend:** удалить unused Inertia CRM pages; rebuild.

**Tests:** feature: guest `/` 200; auth required на `/dashboard`; CRM URLs 404.

**Deploy:** `migrate --force`, `npm run build` если UI. Queue/cron не трогать.

**DoD**

- CRM tables gone; app boots; admin login works; Telegram settings page works; tests green.

**Зависимости:** audit snapshot.

**Не входит:** roles, Nutgram handlers, AI chat, UI Users redesign.

---

## Milestone 1 — Identity / Roles / Users foundation

**Статус.** COMPLETED (2026-09-03).

**Цель.** `owner` vs `user`, access_code, раздельный web access.

**Реализуем**

- `users.role` (`owner`|`user`); `users.access_code` unique; `users.status`; `users.timezone` IANA (можно отложить колонку до M4/M10, контракт — здесь).
- Capability defaults из role (проверка в Core, не россыпь `if role`).
- Owner seed/migration: существующий admin → `role=owner`, `access_code=2000` (если свободно).
- Генерация human-readable unique codes для новых `user` (retry + unique).
- Middleware: owner → admin routes; user → cabinet routes; user на admin → 403 или redirect.
- Policies skeleton; не полагаться на меню.
- Settings Users больше не «admin accounts» (минимальный backend; полный User Card — M7).

**Migrations:** alter `users`; unique `access_code`; check/unique one owner.

**Backend:** User model, generator, `EnsureOwner` / `EnsureCabinetUser`, login redirect by role.

**Frontend:** login redirect; user не видит admin layout. Cabinet shell-заглушка допустима до M8, но **не** admin.

**Tests:** owner dashboard 200; user dashboard 403/redirect; codes unique; `2000` reserved; access_code ≠ password.

**Deploy:** migrate; verify existing login is owner.

**DoD**

- Ровно один owner с кодом `2000`.
- Новый user не попадает в Admin Panel.
- Access code не принимается как web password.

**Зависимости:** M0.

**Не входит:** Telegram pairing, conversations, AI chat, User Card UI полный.

---

## Milestone 2 — Telegram pairing

**Статус.** COMPLETED (2026-09-03).

**Цель.** Бот отвечает. Pairing кодом. Без AI.

**Реализуем**

- Nutgram обрабатывает webhook (secret уже есть).
- `/start` без identity: статический запрос кода (и diagnostic, что handler жив).
- Текст без identity: parse access_code → lookup User.
- Fail: системный отказ; auth event optional; **не** User create; **не** AI.
- Success: `channel_identities` (telegram, external id unique, names, linked_at).
- Owner `2000` и user codes — один код.
- Повторный `/start` при linked identity: без повторного кода (короткое системное до M4).
- Owner: unlink Telegram + regenerate code (API/admin minimal).

**Migrations:** `channel_identities` unique `(channel, external_id)`.

**Backend:** Telegram Adapter pairing service; Nutgram handlers **без** LLM; webhook передаёт update в Nutgram.

**Frontend:** на User Card хотя бы linked yes/no + unlink (полный card — M7).

**Tests:** start unknown; bad code; good code links; same telegram cannot bind two users; linked start no re-ask; no AI client called.

**Deploy:** webhook already set; smoke `/start` on real bot after deploy.

**DoD**

- `/start` реально отвечает.
- Неавторизованный Telegram user AI не получает.
- Верный code связывает identity ↔ User.
- Неверный code не пускает.

**Зависимости:** M1.

**Не входит:** Conversation persist, LLM greeting (подключается в M4), groups.

---

## Milestone 3 — Conversations / Messages Core

**Статус.** COMPLETED (2026-09-03). Telegram Chat Selector (originally Milestone 6) delivered here.

**Цель.** Канало-нейтральный persist.

**Реализуем**

- Tables `conversations`, `messages`.
- Conversation Service + `channel_identities.active_conversation_id` (должен принадлежать тому же user).
- При pairing (wire в M4/M5): создать `Основной`, сделать active.
- Inbound/outbound DTO; persist до и после AI (AI ещё stub/no-op).
- Channel-neutral: cabinet сможет писать в те же таблицы в M8.

**Migrations:** conversations, messages + indexes (user_id, channel_message_id uniqueness).

**Backend:** services/models; ещё не обязательно полный LLM.

**Frontend:** нет пользовательского чата (кроме admin later).

**Tests:** persist inbound; idempotent telegram message id; ownership on conversation.

**Deploy:** migrate only.

**DoD**

- Сообщение авторизованного Telegram user пишется в БД (даже если ответ ещё системный/empty).
- Рестарт не теряет raw.

**Зависимости:** M2 (identity). Можно параллелить модели с концом M2, не handler AI.

**Не входит:** Context builder LLM, groups, memory.

---

## Milestone 4 — AI Configurations Runtime

**Статус.** COMPLETED (2026-09-03). Personal Telegram DM AI (originally Milestone 5) shipped in this milestone.

**Цель.** Chat/complete. Три независимых config. Greeting после pairing в `Основной`.

**Реализовано**

- `AiProviderClient`: `chat(AiChatRequest): AiChatResponse` (tools reserved, not executed).
- Configs: **Owner Conversation AI**, **Owner Analysis AI**, **Default User Conversation AI** in `ai_role_settings`. Не наследование owner→user.
- User General Prompt (`user_ai_settings`); Cabinet + Owner Profile edit.
- `AiConfigurationResolver` по `user.role`.
- Pairing → `Основной` → greeting соответствующим conversation config (application event, без fake user hello).
- Paired Telegram text: persist inbound → current-chat context → Conversation AI → persist assistant → send.
- Analysis config есть; jobs later. User DM никогда не зовёт Owner Conversation / Analysis.
- Runtime source of truth = `ai_role_settings`. `ai_provider_settings.is_active` не используется conversation runtime.

**Migrations:** `ai_role_settings`, `user_ai_settings`, `messages.parent_message_id`.

**Frontend:** Settings → AI: Provider Credentials + три блока. Cabinet AI Settings.

**Tests:** resolver, context isolation, inbound/assistant persist, failure, duplicate, greeting, prompt isolation, admin AI forbidden. Http::fake / FakeAiChatGateway. No live LLM.

**Deploy:** migrate; owner задаёт Conversation provider/model/prompt. At M4 deploy no provider keys were stored.

**DoD** — met. Analysis jobs, tools, groups, memory — later.


---

## Milestone 5 — Owner Telegram AI

**Статус.** COMPLETED (2026-09-03, delivered with Milestone 4).

**Цель.** Первый полноценный Jarvis MVP: owner в Telegram с историей.

Personal DM persist → recent context of the **current** conversation → Conversation AI → send is implemented in M4 for both owner and paired users. Restart-safe history remains in `messages`. Analysis/tools/groups still later.

---

## Milestone 6 — Telegram Chat Selector

**Статус.** COMPLETED (2026-09-03, delivered with Milestone 3).

**Цель.** Telegram и Cabinet делят каталог chats. Active conversation на identity.

**Реализуем**

- Команды/кнопки «Чаты»: список, выбрать, New Chat, текущий.
- Ответ `Выбран чат «<name>».`
- New Chat = `conversations` row, виден в Cabinet.
- `active_conversation_id` ownership check.

**Migrations:** колонка active, если не в M3.

**Backend:** Nutgram menu; Conversation Service.

**Frontend:** Cabinet список уже совместим по данным (UI M8).

**Tests:** switch chat; new chat isolated raw; cannot activate чужой id.

**Deploy:** smoke bot menu.

**DoD**

- DM после выбора идут в выбранный chat.
- Web и Telegram видят один каталог.

**Зависимости:** M3, M5.

**Не входит:** reminders, groups.

---

## Milestone 7 — Users Admin

**Цель.** Каталог Users и User Card для owner.

**Реализуем**

- Users table UI: колонки из [USERS_AND_CABINET.md](USERS_AND_CABINET.md).
- User Card: profile, role, status, access_code, regenerate, Telegram status, unlink, password set/reset, links to Chats/Topics/AI Settings/Open Cabinet.
- Chats list+read (после M3).
- Topics/AI settings: слоты (topics могут быть empty до M12).
- Impersonation skeleton (полный cabinet — M8).

**Migrations:** нет обязательных.

**Backend:** admin controllers + policies owner-only.

**Frontend:** Inertia Users + User Card.

**Tests:** user cannot open Users; owner can; regenerate uniqueness; unlink identity.

**Deploy:** build.

**DoD**

- Owner управляет кодами и Telegram link.
- User Card не доступен `role=user`.

**Зависимости:** M1, M3; impersonate UX зависит от M8.

**Не входит:** group admin, integrations.

---

## Milestone 8 — User Cabinet

**Статус.** COMPLETED (2026-09-03). Delivered as Web Cabinet Chat UI (requested as Milestone 5 in the product sequence after M4 runtime).

**Цель.** `role=user` работает в Web как ChatGPT-минимум.

**Реализовано**

- Login → cabinet → `/cabinet/chats/{id}` (auto `Основной`).
- Свой General Prompt (`/cabinet/ai-settings`).
- Chats list, Новый чат, history, composer → `ConversationTurnService` + Default User Conversation AI.
- Same `conversations` / `messages` as Telegram. `channel=web` + UUID idempotency.
- Strict ownership. Telegram adapter calls the same turn service.

**Frontend:** cabinet sidebar + messenger UI; Load older; timezone display.

**Tests:** own/foreign chats; create/rename; web persist; idempotency; shared Telegram+web history; General Prompt; AI failure; admin routes forbidden.

**DoD** — met. Group UI, tools, User Card impersonation — later.


---

## Milestone 9 — User Telegram AI

**Цель.** Обычные users в DM на том же engine и том же Chat Selector.

**Реализуем**

- Тот же authorized Telegram path, что owner.
- **Default User Conversation AI**, не Owner Conversation / Analysis.
- User General Prompt + isolated User Space.
- Тот же Chat Selector (M6): каталог Cabinet = Telegram.
- Capabilities: chat, memory, telegram_dm, reminders later; deny groups/Google.

**Migrations:** нет.

**Backend:** capability checks (deny projects/groups/gmail/calendar).

**Frontend:** нет.

**Tests:** two users two identities; no cross history; greeting uses Default User Conversation AI; cannot activate чужой chat.

**Deploy:** smoke.

**DoD**

- User после своего кода получает AI в Telegram и cabinet на одном каталоге conversations.
- Owner AI config не используется.

**Зависимости:** M5, M6, M8.

**Не входит:** groups, analysis, Google.

---

## Milestone 10 — Reminder foundation — **DONE 2026-09-03**

**Цель.** Reminders как Core subsystem. Доступны owner и users. Delivery только Telegram. Раньше Google.

**Сделано** по [REMINDERS.md](REMINDERS.md).

- `reminders` table + `ReminderService` + `create_reminder` tool + Gemini function calling + multi-tool loop (max 5).
- Relative dates через AI + `users.timezone`; store `run_at` UTC. IANA побеждает offset. DST через DateTimeZone.
- Нет Telegram identity → reminder **не создаётся**; сообщение подключить Telegram.
- Scheduler `jarvis:reminders:dispatch` everyMinute; production cron `schedule:run`.
- Delivery: `⏰ Напоминание: {text}` через существующий Telegram bot. Не AI turn.
- Recurrence schema есть, логика не реализована.

**DoD**

- «Напомни завтра в 11…» создаёт reminder текущего `user_id` и доходит в Telegram.
- Google не участвует.

**Не входит:** web/email/push delivery; recurrence; Google Calendar; cancel/list tools; reminder admin CRUD.

---

## Milestone 11 — Telegram Groups

**Статус.** COMPLETED (2026-09-03).

**Цель.** Owner-only groups: discovery, persist, admin chat, outbound, passive.

**Реализуем** как [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

**Migrations:** `telegram_groups`, participants optional; conversation `kind=group`.

**Backend:** group branch in adapter; Group Messaging Service; owner policies.

**Frontend:** Admin Telegram Groups list + messenger + compose.

**Tests:** first update creates group; user role 403 on group admin; no auto-reply; outbound via adapter persist.

**Deploy:** bot rights; migrate.

**DoD**

- Группа появляется без ручного ID.
- User не видит группы.
- Jarvis в группу сам не пишет (кроме admin outbound).

**Зависимости:** M3, M1. Analysis не обязателен.

**Не входит:** group summaries (M14).

---

## Milestone 12 — Structured Memory

**Статус.** COMPLETED (2026-09-03).

**Цель.** Topics/memories/summaries per user; Owner Analysis AI на extract/classify. Summary-first cross-chat.

**Реализуем** по [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md).

- Conversation summaries; targeted raw-on-demand.
- Не класть raw Chat B в Chat A автоматически.

**Migrations:** topics (owner scope), memories, sources, conversation summaries, revisions.

**Backend:** retriever, extractor jobs (queue — завести worker если ещё нет).

**Frontend:** User Card Topics (owner); optional cabinet later.

**Tests:** retrieval scoped; no cross-user; raw not deleted; other-chat raw not in default package.

**Deploy:** queue worker **нужен** (systemd/supervisor) — первое deployment change очереди.

**DoD**

- Prompt не содержит всю историю и не содержит raw чужих chats.
- Facts с provenance и `user_id`.

**Зависимости:** M4, M5/M9.

**Не входит:** vector DB, group analysis, Projects.

---

## Milestone 13 — Projects

**Статус.** COMPLETED (2026-09-03).

**Цель.** Owner Space контейнеры. Project ≠ Topic. Relations, не копии.

**Реализуем** по [PROJECTS.md](PROJECTS.md).

- `projects` + `project_conversations` / `project_topics` / `project_memories`. `project_groups` отложен до Groups subsystem.
- Capability `projects` = owner. User 403.
- Owner Conversation AI: project lookup по запросу, не все projects в каждый prompt.

**Migrations:** projects + relation tables.

**Backend:** services + owner policies.

**Frontend:** Admin Projects (минимальный CRUD + attach).

**Tests:** user denied; relation не дублирует messages; conversation может быть в нескольких projects.

**Deploy:** migrate; build.

**DoD**

- Owner связывает chats/topics/groups с project `JARVIS` без копирования raw.

**Зависимости:** M12. Groups attach — после M11.

**Не входит:** GitHub/files; user-level projects.

---

## Milestone 14 — Group Analysis

**Статус.** COMPLETED (2026-09-04).

**Цель.** Owner Analysis AI по group raw: Summary / Decision / Task / Event-Fact. Hierarchical jobs. Group timezone.

**Реализовано** owner-only (`group_analysis`); group knowledge ≠ personal memory. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

- Date range per `telegram_groups.timezone` (fallback owner timezone).
- Никогда не слать весь archive одним prompt: chunk → per group → reduce.
- Tables: `telegram_group_analysis_runs`, `telegram_group_knowledge`, `telegram_group_knowledge_sources`, `telegram_group_knowledge_revisions`.
- Manual Admin/CLI run; no auto analysis on inbound group messages.
- `get_project_context` may include bounded ACTIVE derived group knowledge; never raw group dump.
- Personal `ConversationContextBuilder` / `PersonalMemoryRetriever` do not ingest group knowledge.

**Frontend:** Admin Telegram Group page — Analyze today / yesterday / last 7 days / custom range; tabs Summary, Decisions, Tasks, Events/Facts, Analysis Runs; source message highlight.

**Tests:** `tests/Feature/GroupAnalysisTest.php` (fake Owner Analysis AI; no live Gemini).

**Deploy:** existing memory/default worker also consumes `analysis`.

**Не входит:** auto-reply; owner DM dedicated Group Search tool (M15).

---

## Milestone 15 — Group Knowledge Search

**Статус.** COMPLETED (2026-09-04).

**Цель.** Owner Conversation AI получает group knowledge только через explicit tool.

**Реализовано**

- Owner-only tool `search_group_knowledge` (capability `group_analysis`). Same runtime for Telegram DM and Web Cabinet.
- Channel-neutral `GroupKnowledgeSearchService`: group/project resolution, per-group timezone ranges, derived-first search, bounded raw fallback, participant name match, coverage/staleness.
- Missing/stale analysis may queue existing M14 `GroupAnalysisRunService`; tool never waits for the job.
- `ConversationContextBuilder` still injects zero group knowledge / raw. No personal memory writes.
- Config: `config/group_search.php`. No migration. No Admin search UI. No Vector DB.

**Migrations:** none.

**Frontend:** нет.

**Tests:** `tests/Feature/GroupKnowledgeSearchTest.php` (fake AI/provider; no live Telegram/Gemini). Existing M11–M14 suites remain green.

**Deploy:** workers already (`analysis,memory,default` + `telegram`). Manual live smoke deferred by Owner.

**Не входит:** user access; proactive alerts; tool execution logs (M16).

---

## Milestone 16 — Integration Framework

**Статус.** COMPLETED (2026-09-04).

**Цель.** Registry + encrypted accounts + tools + logs + owner check. Multi-step tool loop.

**Реализовано** [INTEGRATIONS.md](INTEGRATIONS.md) каркас без Google OAuth.

- Code `IntegrationRegistry`: google / telegram / elevenlabs descriptors.
- `integration_accounts` + Laravel-encrypted credentials; hidden from UI/JSON.
- Telegram card reads `telegram_bot_settings` only — no second token store.
- `ToolExecutionService` + `tool_execution_logs` + confirmation policy skeleton.
- Existing Core tools pass through the wrapper; production tool list unchanged.
- Owner Settings → Integrations; normal user 403.

**Migrations:** `2026_09_04_020000_create_integration_framework_tables` (batch 13).

**Frontend:** Settings → Integrations cards + Recent Tool Executions.

**Tests:** `tests/Feature/IntegrationFrameworkTest.php` (fake tools/provider; no live Google/ElevenLabs/Telegram/Gemini).

**Deploy:** migrate; `npm run build`.

**Не входит:** live Google/ElevenLabs calls; OAuth connect; Calendar/Gmail tools.

---

## Milestone 17 — Google OAuth

**Статус.** COMPLETED (2026-09-04).

**Цель.** Owner connect Google.

**Реализовано**

- Authorization Code + PKCE (S256), session state (TTL, one-time, owner-bound).
- Callback exchanges code, fetches OpenID userinfo (`sub` + email), upserts `integration_accounts`.
- Encrypted access + refresh + `expires_at`. Existing refresh_token is never overwritten by an absent response.
- `GoogleCredentialService::getValidAccessToken()` with lockForUpdate refresh and skew.
- `invalid_grant` → revoked; disconnect wipes local credentials and attempts remote revoke.
- Identity scopes only: `openid email profile`. Calendar/Gmail incremental later.
- One active Google account per owner; same `sub` reconnects in place.
- Missing env → Not configured; Connect disabled.

**Migrations:** none. Credentials stay in `credentials_encrypted`.

**Frontend:** Settings → Integrations Google card: Connect / Reconnect / Disconnect.

**Tests:** `tests/Feature/GoogleOAuthTest.php` (`Http::fake` only).

**Deploy:** set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, optional `GOOGLE_REDIRECT_URI`; then `php artisan config:clear`. Do not `config:cache` unless following current deploy practice.

**Не входит:** Calendar/Gmail API; AI tools.

---

## Milestone 18 — Google Calendar

**Статус.** COMPLETED (2026-09-04).

**Цель.** Tools read/write calendar. Не reminder engine.

**Реализовано.** Incremental Calendar OAuth; `GoogleCalendarService`; owner Calendar tools; persisted `tool_confirmations`; create idempotency; no local event mirror.

**Migrations:** `tool_confirmations` only.

**Не входит:** Gmail; live Google smoke (deferred).

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

---

## Milestone 19 — Gmail

**Статус.** COMPLETED (2026-09-04).

**Цель.** Mail tools + confirmation на write.

**Реализовано.** Incremental Gmail OAuth (readonly + compose + modify, not `mail.google.com`); `GoogleGmailService` + MIME parser/builder; owner Gmail tools; send always uses persisted `tool_confirmations`; no local mailbox; no polling/watch; no attachment download/send.

**Migrations:** none.

**Не входит:** user-level Gmail; live Google smoke (deferred).

После M19 — combined live Google smoke (Owner): connect Google → enable Calendar → enable Gmail → read calendar → freebusy → create/update/delete test event → Gmail read/draft/send → token refresh → disconnect/reconnect. Do not run now.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

---

## Milestone 20 — Combined Google smoke / hardening

**Статус.** DEFERRED BY OWNER. Validation phase — не обязательно coding milestone.

**Цель.** Owner включает Google Cloud (Calendar API + Gmail API + OAuth env) и проверяет M17–M19 live. Hardening only if smoke finds defects.

**Не реализовывать код «чтобы было».** Не подключать production inbox из Cursor.

Plan: [INTEGRATIONS.md](INTEGRATIONS.md), [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

**Зависимости:** M17–M19.

---

## Milestone 21 — GitHub Integration

**Статус.** COMPLETED (2026-09-04) — implementation only. Automated tests and live GitHub smoke DEFERRED BY OWNER.

**Цель.** Owner-only GitHub through Integration Framework. Tools, not Telegram adapter.

**Реализовано.** GitHub OAuth App (`repo` + `read:org`); encrypted `integration_accounts`; `GitHubCredentialService` / `GitHubApiService`; Integrations card; read tools (repos/branches/commits/compare/file/search/issues/PRs/workflows); controlled write (issue/comment/branch/PR create). No merge/delete/force/file-write. No local git/mirror/webhook. No PAT field.

**Migrations:** none.

**Не входит:** live OAuth; GitHub App installations; merge; workspace/desktop/mobile.

**Зависимости:** M16.

После M21 — M22 Owner Web Workspace.

---

## Milestone 22 — Owner Web Workspace

**Цель.** Полноценный owner-facing Personal Workspace. Conversation в центре. Admin Panel остаётся технической.

**Реализуем** [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md). Same Core as Telegram. Route `/jarvis`. Existing Laravel + Inertia/React in `Owiiiii1/JARVIS`.

Versioned Client API remains later (Desktop/Mobile). M22 uses Inertia session.

**Status.** IMPLEMENTED / PARTIAL MANUAL PASS (2026-09-04). Automated tests not run. No live AI/Google/GitHub send. Voice runtime not included. Owner later confirmed specific Workspace image/Storage/web-search functions; the Workspace milestone as a whole is not fully validated.

**Зависимости:** M4, M8, M19; желателен M20 smoke.

---

## Milestone 22.1 — Multimodal Chat Images + Copyable Artifacts

**Статус.** PARTIAL MANUAL PASS (2026-09-04). Automated tests not run.

- Image upload: **MANUAL PASS**
- Vision recognition (Gemini, Workspace): **MANUAL PASS**
- screenshot expiry/purge lifecycle: IMPLEMENTED / NOT VALIDATED
- artifact rendering: IMPLEMENTED / NOT VALIDATED (not separately Owner-checked)

**Цель.** Owner Workspace can send PNG/JPEG/WebP with a turn through the existing Conversation Engine, and assistant copy-paste payloads render as distinct Artifact blocks.

**Реализовано.** `message_attachments`; private storage; multipart `images[]`; `AiContentPart`; Gemini current-turn vision; OpenAI/Anthropic `vision_not_supported`; SafeMarkdown code vs artifact.

**Не входит:** Telegram photos; GIF; historical image replay; Cabinet composer; Desktop/Mobile UI. Artifact copy UX was not a separate Owner check.

**Зависимости:** M22, M4.

После M22.1 — M22.2 Persistent Storage + ephemeral screenshots.

---

## Milestone 22.2 — Persistent Storage + Ephemeral Screenshots + Workspace UX

**Статус.** PARTIAL MANUAL PASS (2026-09-04). Automated tests not run.

- Text file upload: **MANUAL PASS**
- Storage persistence/read/retrieval: **MANUAL PASS**
- Storage UI основные операции: IMPLEMENTED / NOT VALIDATED
- screenshot summarization/purge: IMPLEMENTED / NOT VALIDATED
- destructive delete confirmation: IMPLEMENTED / NOT VALIDATED

**Цель.** Screenshots remain current-turn + short-lived media with a derived visual summary; owner gets a permanent textual Storage library with chunked retrieval tools; desktop composer keeps focus.

**Реализовано.** Attachment lifecycle columns; 24h/7d retention; `AttachmentVisionSummaryService`; `jarvis:attachments:purge-ephemeral`; `stored_files` + chunks + pivot; `/jarvis/storage`; chat `files[]`; Storage tools; composer focus helper. See [STORAGE.md](STORAGE.md).

**Не входит:** PDF/Office; screenshot library; ContextBudgetManager; user-role Storage. Screenshot lifecycle and Storage library UI were not Owner-validated.

**Зависимости:** M22.1.

После M22.2 — M22.3 Web Research + Context Budget Manager.

---

## Milestone 22.3 — Web Research + Context Budget Manager

**Статус.** PARTIAL MANUAL PASS (2026-09-04). Automated tests not run.

- Web Search via Gemini Google Search: **MANUAL PASS**
- actual current-information retrieval: **MANUAL PASS**
- `fetch_web_page`: IMPLEMENTED / NOT VALIDATED
- ContextBudgetManager: IMPLEMENTED / NOT VALIDATED
- SSRF protections: IMPLEMENTED / NOT VALIDATED
- Tavily: IMPLEMENTED / NOT VALIDATED
- M22.3.1 Admin Gemini Google Search configuration (working path): **MANUAL PASS**
- M22.3.1 Tavily configuration: NOT VALIDATED

**Цель.** Owner web research tools (search then selective fetch) plus a global Context Budget Manager so one LLM request stays bounded regardless of DB size.

**Реализовано.** `WebSearchProvider` (`gemini_google` / `tavily` / `disabled`) + `WebPageFetchService` / SSRF guard; `search_web` / `fetch_web_page`; capability `web_research`; Admin Web Research settings (`web_research_settings` + `WebResearchSettingsService`); `ContextBudgetManager`, `AiModelContextPolicy`, `TokenEstimator`, `ToolResultBudgetManager`; budget-aware incremental summaries. See [WEB_RESEARCH.md](WEB_RESEARCH.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md).

**Не входит:** proving `fetch_web_page` ran in the Owner scenario; PDF fetch; browser automation; web content DB; user-role web tools; Test Connection; Tavily live search; tests.

**Зависимости:** M22.2, M4.

После M22.3 / M22.3.1 — M23 Voice Runtime Foundation.

---

## Milestone 23 — Voice Runtime Foundation

**Status.** IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live STT/TTS/AI/microphone-to-provider.

**Цель.** STT/TTS abstraction + `VoiceRuntimeService` over the existing conversation. Same space, selected `conversation_id`, Conversation Engine, one memory.

**Реализовано.** `voice_sessions`, state machine, provider ports + Null + ElevenLabs TTS adapter + optional OpenAI Whisper STT port, HTTP Workspace Voice client, Admin Voice/Speech settings, ephemeral audio + cleanup. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

**Не реализовывать в M23.** Telephony/Twilio. Final Three.js Orb (M24). Live provider calls during implementation. `php artisan test`.

**Зависимости:** M16, M22, M4.

---

## Milestone 23.2 — Gemini STT Provider

**Status.** IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live STT/TTS/AI.

**Цель.** Dedicated `GeminiSpeechToTextProvider` on the STT port. Reuse existing Gemini `ai_provider_settings` credential. Keep Conversation AI unchanged. Keep ElevenLabs TTS.

**Реализовано.** `SpeechToTextManager` keys `none` / `gemini` / `openai`. Admin STT select + `stt_model` (default `gemini-3.5-transcribe`). Gemini API `generateContent` transcription adapter. `GeminiCredentialResolver`. Additive `voice_settings.stt_model`. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

**Не реализовывать.** Live Gemini transcription. Live ElevenLabs. Live Conversation AI. Second Gemini key. `AiChatGateway` transcription. Telephony. Conversation AI provider/model change. `php artisan test`.

**Зависимости:** M23, M4.

---

## Milestone 24 — Voice UI / Orb

**Status.** IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live STT/TTS.

**Цель.** Animated 3D Orb + transcript + controls. Provider-neutral `VoiceVisualizationState`.

**Реализовано.** Three.js + custom GLSL in `resources/js/voice/visualization`. Local Web Audio analyser. Demo mode (`?voice_demo=1`). WebGL/CSS fallback. Reduced motion. [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

**Не реализовывать в M24.** Telephony. Live speech providers. Conversation Engine changes.

**Зависимости:** M23.

---

## Milestone 24.1 — Hands-Free Voice Conversation + VAD + Audio Compatibility

**Status.** IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live STT/TTS/AI.

**Цель.** Cancel push-to-talk. Text→Voice auto-starts listening. One mic = mute. Local VAD ends turns. Auto-listen after TTS. Canonical MIME + matching upload filename. Recoverable invalid-state races.

**Реализовано.** `VoiceTurnDetector`, `VoiceAudioMime`, workspace `voiceClient` MIME negotiation, unmute = `resume` (idle) + one `listen`, barge-in with stronger threshold (plus Interrupt fallback). Same `VoiceSession` on `/jarvis` and `/chat`.

**Не реализовывать.** Gemini Live / streaming STT. Wake word. Admin VAD UI. Continuous audio archive. Live provider smoke. `php artisan test`.

**Зависимости:** M23, M23.2, M24.

---

## Milestone 25U.1 — Shared Personal Workspace + Full User Chat

**Status.** IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live AI / Web Search / Voice.

**Цель.** Owner and User share one Personal Workspace. User `/chat` gets full chat (images, files, voice, web research, memory, General Prompt). No second engine. No Cabinet product fork.

**Реализовано.** Shared `PersonalWorkspace` frontend; `/chat` + `/jarvis` aliases; `/cabinet` redirects; default user capabilities include `web_research`, `voice`, `storage` (read tools); `delete_storage_file` owner-only; same Orb/VoiceRuntime.

**Не реализовывать в M25U.1.** User Administration lifecycle (M25U.2). Storage page for users. Projects/integrations for users. Self-registration. Live provider validation. `php artisan test`.

**Зависимости:** M8, M22, M23, M24.

---

## Milestone 25U.2 — User Administration + Isolation Hardening

**Status.** IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live AI / Web Search / Voice / Telegram. No production test users created.

**Цель.** Owner manages ordinary Jarvis users: create, User Card, active/disabled, password reset, Telegram code/unlink, impersonation. Harden backend isolation. No self-registration.

**Реализовано.** Users catalog + User Card; `UserAdministrationService`; session impersonation; disable over hard delete; self password in `/chat` settings. Isolation remains `user_id` on conversations, attachments, files, memory, voice, reminders, Telegram, General Prompt. [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md).

**Не реализовывать.** Hard delete of users. Production User A/B. Live provider calls. `php artisan test`.

**Зависимости:** M25U.1.

---

## Milestone 25 — Desktop Client Foundation

**Цель.** Tauri 2 + React/TS client in `Owiiiii1/JARVIS-Desktop`. Thin client, Client API, same conversations.

**Реализуем** [CLIENTS/DESKTOP_APP.md](CLIENTS/DESKTOP_APP.md). Tray/hotkey/updater can follow.

**Не реализовывать сейчас.** Production Laravel tree без Tauri/Rust.

**Зависимости:** M22 Client API, желательны M23–M24.

---

## Milestone 26 — Mobile Client Foundation

**Цель.** Flutter iOS/Android in `Owiiiii1/JARVIS-Mobile`. Same Core. No direct Google APIs.

**Реализуем** [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md).

**Не реализовывать сейчас.**

**Зависимости:** M22 Client API; желательны M23–M24.

---

## Milestone 27 — Proactive Assistant / monitoring

**Цель.** Placeholder, не MVP. `event/trigger` → policy → relevance → Conversation/Notification.

Reminders (M10) — первый scheduled trigger. Inbox watch / calendar conflict / group decision — later.

**Зависимости:** M10; желательны M15, M18, M19, M21.

**Не входит в MVP.** Не реализовывать сейчас.

---

## Milestone 28+ — Human-like layer / polish

**Цель.** Latency, turn-taking, references, incomplete phrases, initiative; notifications; wake word; files; further integrations.

**Реализуем** [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md) над существующим Core.

**Зависимости:** M12, M23–M26.

**Не входит:** смена vendor lock-in; перепись storage.

---

## Карта фаз → вехи

| Фаза docs | Вехи |
| --- | --- |
| Cleanup | 0 |
| Phase 1 identity + Telegram MVP | 1–5 |
| Chat Selector | 6 |
| Users / Cabinet / User Telegram | 7–9 |
| Reminder foundation | 10 |
| Groups persist | 11 |
| Phase 2 memory + Projects | 12–13 |
| Group analysis + search | 14–15 |
| Integrations / Google | 16–19 |
| Google live smoke | 20 (validation) |
| GitHub | 21 |
| Owner Workspace + Client API | 22 |
| Voice runtime / Orb | 23–24 |
| Desktop / Mobile repos | 25–26 |
| Proactive (future) | 27 |
| Phase 4 / polish | 28+ |

---

## Общие запреты на всех вехах

- Нет LLM в Nutgram/UI.
- Нет auto User из Telegram.
- Нет access_code как web password.
- Нет cross-user retrieval.
- Нет Google SDK в Telegram adapter.
- Owner Conversation AI не резолвится для `role=user`.
- Analysis AI не обслуживает user DM.
- Chat A не получает автоматически raw Chat B.
- Reminder ≠ Calendar Event.
- CURRENT_STATE не обновлять как «уже сделано» до реального кода.
- Admin Panel ≠ Personal Workspace. Не делать Admin основным чатом owner.
- Tauri/Flutter не живут в Laravel repo.
- Клиенты не исполняют tools и не хранят Google/GitHub credentials.
