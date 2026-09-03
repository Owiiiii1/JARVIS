# Implementation plan

Исполняемые вехи. Не путать с уже работающим кодом: [CURRENT_STATE.md](CURRENT_STATE.md).

Порядок фиксирован. Не начинать Google/Voice до Identity + Conversation + AI roles.

Исходная база: Laravel 13, Inertia/React, custom-admin-kit v0.5.0, Nutgram 4.50.0, webhook ACK-only, один admin login для всех `users`, CRM leftover, AI = listModels + one `is_active`.

---

## Milestone 0 — Cleanup / Baseline

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

**Цель.** `owner` vs `user`, access_code, раздельный web access.

**Реализуем**

- `users.role` (`owner`|`user`); `users.access_code` unique; `users.status`.
- Owner seed/migration: существующий admin → `role=owner`, `access_code=2000` (если свободно).
- Генерация human-readable unique codes для новых `user` (retry + unique).
- Middleware: owner → admin routes; user → cabinet routes; user на admin → 403 или redirect.
- Policies skeleton; не полагаться на меню.
- Settings Users больше не «admin accounts» (минимальный backend; полный User Card — M6).

**Migrations:** alter `users`; unique `access_code`; check/unique one owner.

**Backend:** User model, generator, `EnsureOwner` / `EnsureCabinetUser`, login redirect by role.

**Frontend:** login redirect; user не видит admin layout. Cabinet shell-заглушка допустима до M7, но **не** admin.

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

**Frontend:** на User Card хотя бы linked yes/no + unlink (полный card — M6).

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

**Цель.** Канало-нейтральный persist.

**Реализуем**

- Tables `conversations`, `messages`.
- Conversation Service: resolve/create personal conversation (Telegram DM 1:1 mapping `TBD` — один active telegram conversation на user).
- Inbound/outbound DTO; persist до и после AI (AI ещё stub/no-op).
- Channel-neutral: cabinet сможет писать в те же таблицы в M7.

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

## Milestone 4 — AI Roles Runtime

**Цель.** Настоящий chat/complete. Roles `conversation` и `analysis`. Greeting после pairing.

**Реализуем**

- Расширить `AiProviderClient`: chat/complete (messages, system, params, usage).
- Platform config **per role** (не один `is_active` на весь продукт).
- Platform Prompt + User General Prompt storage.
- `resolve(role, user_id)` + inheritance.
- После успешного pairing: системное подтверждение optional + **Conversation AI greeting**.
- Mocks/fakes в тестах. Analysis role конфиг есть, jobs — later.

**Migrations:** role settings (новая таблица или expand `ai_provider_settings`); `user_ai_settings.general_prompt`.

**Backend:** AI Layer rewrite; Conversation Engine вызывает только `conversation` role; Settings UI: два блока Conversation / Analysis.

**Frontend:** Settings AI role-based (сломать «one global model»).

**Tests:** mock provider; pairing triggers one conversation complete; analysis not used on DM; user prompt included; platform prompt cannot be omitted.

**Deploy:** migrate; owner задаёт Conversation provider/model/prompt.

**DoD**

- Pairing заканчивается AI-приветствием, не только «Вы авторизованы».
- Roles разделены в config.
- Нет LLM в Nutgram handler.

**Зависимости:** M2, M3.

**Не входит:** Analysis jobs, tools, groups.

---

## Milestone 5 — Owner Telegram AI

**Цель.** Первый полноценный Jarvis MVP: owner в Telegram с историей.

**Реализуем**

- Авторизованный owner DM: persist → recent context → Conversation AI → send.
- Restart-safe: history из БД.
- Recent window N.

**Migrations:** нет, если M3 полон.

**Backend:** wire authorized path (уже в M4 greeting) на каждое DM.

**Frontend:** нет.

**Tests:** two-turn history; restart simulation reads DB; unlinked still no AI.

**Deploy:** smoke owner bot `2000` если ещё не paired.

**DoD**

- Owner ведёт диалог в Telegram; рестарт PHP не обнуляет контекст.
- User без pairing по-прежнему только code flow.

**Зависимости:** M4.

**Не входит:** Cabinet, groups, memory extraction, Google.

---

## Milestone 6 — Users Admin

**Цель.** Каталог Users и User Card для owner.

**Реализуем**

- Users table UI: колонки из [USERS_AND_CABINET.md](USERS_AND_CABINET.md).
- User Card: profile, role, status, access_code, regenerate, Telegram status, unlink, password set/reset, links to Chats/Topics/AI Settings/Open Cabinet.
- Chats list+read (после M3).
- Topics/AI settings: слоты (topics могут быть empty до M10).
- Impersonation skeleton (полный cabinet — M7).

**Migrations:** нет обязательных.

**Backend:** admin controllers + policies owner-only.

**Frontend:** Inertia Users + User Card.

**Tests:** user cannot open Users; owner can; regenerate uniqueness; unlink identity.

**Deploy:** build.

**DoD**

- Owner управляет кодами и Telegram link.
- User Card не доступен `role=user`.

**Зависимости:** M1, M3; impersonate UX зависит от M7.

**Не входит:** group admin, integrations.

---

## Milestone 7 — User Cabinet

**Цель.** `role=user` работает в Web как ChatGPT-минимум.

**Реализуем**

- Login → cabinet.
- Profile (name/password).
- Chats list, New Chat, history, input → Conversation Engine.
- Strict ownership.

**Migrations:** нет.

**Backend:** cabinet routes + policies.

**Frontend:** cabinet layout, messenger UI, lazy history.

**Tests:** user A cannot open B conversation; New Chat empty raw; same user prompt on all chats; user blocked from `/settings`.

**Deploy:** build.

**DoD**

- User логинится в cabinet, не в admin.
- Несколько независимых chats, одна long-term memory scope (пока recent per chat + shared prompt).

**Зависимости:** M4, M1. M5 желателен (тот же engine).

**Не входит:** group UI, tools.

---

## Milestone 8 — User Telegram AI

**Цель.** Обычные users в DM на том же engine.

**Реализуем**

- Тот же authorized Telegram path, что owner.
- User General Prompt + isolated history/memory scope.
- Tools/groups не доступны user.

**Migrations:** нет.

**Backend:** permission checks on tools (deny user).

**Frontend:** нет.

**Tests:** two users two identities; no cross history; user pairing greeting uses their prompt.

**Deploy:** smoke.

**DoD**

- User после своего кода получает AI в Telegram и cabinet на одних conversations/memory scope.

**Зависимости:** M5, M7.

**Не входит:** groups, analysis.

---

## Milestone 9 — Telegram Groups

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

**Не входит:** group summaries (M11).

---

## Milestone 10 — Structured Memory

**Цель.** Topics/memories/summaries per user; Analysis AI на extract/classify.

**Реализуем** по [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md).

**Migrations:** topics (owner scope), memories, sources, summaries, revisions.

**Backend:** retriever, extractor jobs (queue — завести worker если ещё нет).

**Frontend:** User Card Topics (owner); optional cabinet later.

**Tests:** retrieval scoped; no cross-user; raw not deleted.

**Deploy:** queue worker **нужен** (systemd/supervisor) — первое deployment change очереди.

**DoD**

- Prompt не содержит всю историю.
- Facts с provenance и `user_id`.

**Зависимости:** M4, M5/M8.

**Не входит:** vector DB, group analysis.

---

## Milestone 11 — Group Analysis

**Цель.** Analysis AI по group raw: summaries, decisions, tasks, time range.

**Реализуем** owner queries; group knowledge ≠ user memory.

**Migrations:** group knowledge rows / summaries as needed.

**Backend:** analysis jobs; retrieval filters; owner-only.

**Frontend:** admin group analysis actions `TBD`.

**Tests:** user cannot query group knowledge; full dump not sent.

**Deploy:** workers.

**DoD**

- Owner может получить выжимку; raw сохранён.

**Зависимости:** M9, M10.

**Не входит:** auto-reply policies beyond docs.

---

## Milestone 12 — Integration Framework

**Цель.** Registry + encrypted accounts + tools + logs + owner check.

**Реализуем** [INTEGRATIONS.md](INTEGRATIONS.md) каркас без Google OAuth ещё.

**Migrations:** `integration_accounts`, `tool_execution_logs`.

**Backend:** Tool Registry; permission `owner`; Settings → Integrations page skeleton (Telegram status reuse).

**Frontend:** Integrations list.

**Tests:** user 403; tokens never in logs.

**Deploy:** migrate.

**DoD**

- Каркас готов; Telegram overview без второго token store.

**Зависимости:** M1, M4.

**Не входит:** live Google/ElevenLabs calls.

---

## Milestone 13 — Google OAuth

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

**Зависимости:** M12.

**Не входит:** Calendar/Gmail API calls.

---

## Milestone 14 — Google Calendar

**Цель.** Tools read/write calendar.

**Реализуем** list/read/search/free-busy/create/update/delete + Conversation AI tool defs.

**Migrations:** нет обязательных.

**Backend:** Calendar adapter; tools registered.

**Frontend:** last successful use on Integrations.

**Tests:** mocked Google; user tools denied; write goes through tools.

**Deploy:** scopes include calendar.

**DoD**

- Owner в Telegram/cabinet (если tools enabled) может создать событие через Conversation AI.

**Зависимости:** M13, M5.

**Не входит:** Gmail.

---

## Milestone 15 — Gmail

**Цель.** Mail tools + safety на write.

**Реализуем** search/read/thread/inbox/draft/reply/send/labels; confirm policy `TBD` на send.

**Backend:** Gmail adapter + tools.

**Tests:** mocks; send not in Telegram adapter; user denied.

**Deploy:** gmail scopes.

**DoD**

- Owner читает/шлёт через tools.

**Зависимости:** M13, M5.

**Не входит:** user-level Gmail.

---

## Milestone 16 — Mobile/Desktop API

**Цель.** Public API: auth, chats, messages, realtime transport choice.

**Реализуем** [API.md](API.md). Ownership. Same engine.

**Migrations:** personal access tokens / sanctum if chosen.

**Backend:** `routes/api.php`.

**Frontend:** нет native.

**Tests:** token cannot see other user chats.

**Deploy:** API rate limits `TBD`.

**DoD**

- Документированный auth + CRUD messages.

**Зависимости:** M7, M8.

**Не входит:** native apps, voice.

---

## Milestone 17 — Mobile/Desktop Clients

**Цель.** Тонкие клиенты: profile, chats, text.

**Реализуем** apps hitting M16.

**Tests:** E2E smoke `TBD`.

**Deploy:** store/distribution `TBD`.

**DoD**

- Тот же user видит cabinet/Telegram history.

**Зависимости:** M16.

**Не входит:** voice barge-in.

---

## Milestone 18 — ElevenLabs / Voice

**Цель.** STT/TTS/realtime, тот же engine, interruptions.

**Реализуем** [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Integrations card.

**Deploy:** voice credentials encrypted.

**DoD**

- Голосовая реплика = message того же user.

**Зависимости:** M12, M16/M17, M4.

**Не входит:** Phase 4 human-like quality.

---

## Milestone 19 — Human-like Layer

**Цель.** Latency, turn-taking, references, incomplete phrases, initiative, topic transitions.

**Реализуем** [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md) над существующим Core.

**DoD**

- Не переписывать storage; не один prompt вместо retrieval.

**Зависимости:** M10, M18.

**Не входит:** смена vendor lock-in.

---

## Карта на старые фазы

| Фаза docs | Вехи |
| --- | --- |
| Cleanup | 0 |
| Phase 1 MVP | 1–5 |
| Users/Cabinet | 6–8 |
| Groups | 9 |
| Phase 2 memory | 10–11 |
| Integrations | 12–15 |
| Phase 3 clients/voice | 16–18 |
| Phase 4 | 19 |

---

## Общие запреты на всех вехах

- Нет LLM в Nutgram/UI.
- Нет auto User из Telegram.
- Нет access_code как web password.
- Нет cross-user retrieval.
- Нет Google SDK в Telegram adapter.
- CURRENT_STATE не обновлять как «уже сделано» до реального кода.
