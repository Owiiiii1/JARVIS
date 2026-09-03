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

## Milestone 10 — Reminder foundation

**Цель.** Reminders как Core subsystem. Доступны owner и users. Delivery только Telegram. Раньше Google.

**Реализуем** по [REMINDERS.md](REMINDERS.md).

- Reminder Tool + Reminder Engine + scheduler/worker.
- Relative dates в `users.timezone`; store `run_at` UTC.
- Cross-user reminder запрещён обычному user.
- Нет Telegram identity → сообщить, что для доставки нужно подключить Telegram.
- Не Calendar event.

**Migrations:** `reminders`; `users.timezone` если ещё нет.

**Backend:** tool + worker; Telegram Adapter delivery.

**Frontend:** timezone в Cabinet/profile (M8 слот допустим).

**Tests:** owner и user создают свой reminder; user A не ставит user B; без pairing — отказ/пояснение; Calendar tool не вызывается.

**Deploy:** queue/cron worker.

**DoD**

- «Напомни завтра в 11…» создаёт reminder текущего `user_id` и доходит в Telegram.
- Google не участвует.

**Зависимости:** M5, M9 (оба space умеют DM). M1 timezone.

**Не входит:** web/email/push; recurrence polish; Google Calendar.

---

## Milestone 11 — Telegram Groups

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

**Цель.** Owner Space контейнеры. Project ≠ Topic. Relations, не копии.

**Реализуем** по [PROJECTS.md](PROJECTS.md).

- `projects` + `project_conversations` / `project_topics` / `project_groups` / `project_memories`.
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

**Цель.** Owner Analysis AI по group raw: Summary / Decision / Task / Event-Fact. Hierarchical jobs. Group timezone.

**Реализуем** owner-only; group knowledge ≠ personal memory. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

- Date range per `telegram_groups.timezone` (fallback owner timezone).
- Никогда не слать весь archive одним prompt: chunk → per group → reduce.

**Migrations:** group knowledge rows; `telegram_groups.timezone` если ещё нет.

**Backend:** analysis jobs; retrieval filters; owner-only.

**Frontend:** admin group analysis actions `TBD`.

**Tests:** user cannot query group knowledge; full dump not sent; timezone used for «сегодня».

**Deploy:** workers.

**DoD**

- Owner может получить выжимку; raw сохранён; derived types с provenance.

**Зависимости:** M11, M12.

**Не входит:** auto-reply; owner DM tool search (M15).

---

## Milestone 15 — Group Knowledge Search

**Цель.** Owner Conversation AI получает group knowledge только через explicit tool.

**Реализуем**

- Group Search / Analysis Tool: «анализ за сегодня по всем группам», «что решили в группе 1?», «важное по JARVIS?».
- Не auto-mix groups в owner personal prompt.
- Может запускать hierarchical job (M14) и вернуть result в turn.

**Migrations:** нет обязательных.

**Backend:** tool + capability `group_analysis`.

**Frontend:** нет.

**Tests:** user tool denied; default owner DM package без group dump; explicit query hits stored raw/derived.

**Deploy:** workers already.

**DoD**

- Owner в личке спрашивает группы и получает ответ через tool, не через silent merge.

**Зависимости:** M14, M5.

**Не входит:** user access; proactive alerts.

---

## Milestone 16 — Integration Framework

**Цель.** Registry + encrypted accounts + tools + logs + owner check. Multi-step tool loop.

**Реализуем** [INTEGRATIONS.md](INTEGRATIONS.md) каркас без Google OAuth ещё.

**Migrations:** `integration_accounts`, `tool_execution_logs`.

**Backend:** Tool Registry; capability checks; confirmation policy skeleton; loop ≠ max one call.

**Frontend:** Integrations list.

**Tests:** user 403; tokens never in logs; two sequential mocked tool calls in one turn.

**Deploy:** migrate.

**DoD**

- Каркас готов; Telegram overview без второго token store.

**Зависимости:** M1, M4.

**Не входит:** live Google/ElevenLabs calls.

---

## Milestone 17 — Google OAuth

**Цель.** Owner connect Google.

**Реализуем**

- OAuth 2.0 connect/callback/refresh/disconnect.
- Encrypted tokens; min scopes; diagnostics.
- No tokens in UI/logs.

**Migrations:** token columns on integration account.

**Backend:** OAuth controller owner-only.

**Frontend:** Connect/Reconnect/Disconnect.

**Tests:** fake OAuth; user cannot start connect; disconnect wipes local secrets.

**Deploy:** Google Cloud client id/secret in **encrypted settings or env** (`TBD`); HTTPS already.

**DoD**

- Owner видит Connected + account email/scopes.

**Зависимости:** M16.

**Не входит:** Calendar/Gmail API calls.

---

## Milestone 18 — Google Calendar

**Цель.** Tools read/write calendar. Не reminder engine.

**Реализуем** list/read/search/free-busy/create/update/delete + Owner Conversation AI tool defs + confirmation policy.

**Migrations:** нет обязательных.

**Backend:** Calendar adapter; tools registered.

**Frontend:** last successful use on Integrations.

**Tests:** mocked Google; user tools denied; write через tools; reminder tool не создаёт Calendar event.

**Deploy:** scopes include calendar.

**DoD**

- Owner может создать событие через Conversation AI.
- «Напомни» по-прежнему Reminder Engine (M10).

**Зависимости:** M17, M5, M10.

**Не входит:** Gmail.

---

## Milestone 19 — Gmail

**Цель.** Mail tools + confirmation на write.

**Реализуем** search/read/thread/inbox/draft/reply/send/labels; confirmation policy.

**Backend:** Gmail adapter + tools.

**Tests:** mocks; send not in Telegram adapter; user denied; multi-step Gmail → Calendar.

**Deploy:** gmail scopes.

**DoD**

- Owner читает/шлёт через tools.

**Зависимости:** M17, M5.

**Не входит:** user-level Gmail.

---

## Milestone 20 — Mobile/Desktop API

**Цель.** Public API: auth, chats, messages, realtime transport choice.

**Реализуем** [API.md](API.md). Ownership. Same engine. Тот же каталог conversations.

**Migrations:** personal access tokens / sanctum if chosen.

**Backend:** `routes/api.php`.

**Frontend:** нет native.

**Tests:** token cannot see other user chats.

**Deploy:** API rate limits `TBD`.

**DoD**

- Документированный auth + CRUD messages.

**Зависимости:** M8, M9.

**Не входит:** native apps, voice.

---

## Milestone 21 — Mobile/Desktop Clients

**Цель.** Тонкие клиенты: profile, chats, text.

**Реализуем** apps hitting M20.

**Tests:** E2E smoke `TBD`.

**Deploy:** store/distribution `TBD`.

**DoD**

- Тот же user видит cabinet/Telegram history.

**Зависимости:** M20.

**Не входит:** voice barge-in.

---

## Milestone 22 — ElevenLabs / Voice

**Цель.** STT/TTS/realtime. Тот же User Space, selected conversation, Conversation Engine, space AI config, одна memory.

**Реализуем** [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Transport/STT/TTS/`TBD` практикой.

**Deploy:** voice credentials encrypted.

**DoD**

- Голосовая реплика = message того же user и той же conversation.
- Нет отдельных voice memories.

**Зависимости:** M16, M20/M21, M4.

**Не входит:** Phase 4 human-like quality; autonomous proactive.

---

## Milestone 23 — Proactive Engine (future)

**Цель.** Placeholder, не MVP. `event/trigger` → policy → relevance → Conversation/Notification → Telegram.

**Реализуем** позже: group important decision, important email, calendar conflict, approaching event.

Reminders (M10) — первый простой scheduled trigger. Autonomous proactive **не** делать раньше integrations.

**Зависимости:** M10; желательны M15, M18, M19.

**Не входит в MVP.** Не реализовывать сейчас.

---

## Milestone 24 — Human-like Layer

**Цель.** Latency, turn-taking, references, incomplete phrases, initiative, topic transitions.

**Реализуем** [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md) над существующим Core.

**DoD**

- Не переписывать storage; не один prompt вместо retrieval.

**Зависимости:** M12, M22.

**Не входит:** смена vendor lock-in.

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
| Phase 3 clients/voice | 20–22 |
| Proactive (future) | 23 |
| Phase 4 | 24 |

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
