# Jarvis — current implementation snapshot

**Date:** 2026-09-04  
**Host path:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git

This document describes **what exists on the server now**. Target architecture in other `Docs/` files is **not** treated as implemented.

### Implemented vs planned (post-M22)

**IMPLEMENTED (code/runtime, Google/GitHub/Workspace/vision not live-validated):** Admin Panel; Telegram pairing/DM/Chat Selector; User Cabinet chat (`/cabinet`); Owner Personal Workspace (`/jarvis`) including M22.1 image attachments + copyable artifacts; Conversation Engine; Memory Engine; Projects; Telegram Groups + analysis/search; Reminder Engine; Integration Framework; Google OAuth + Calendar tools + Gmail tools; GitHub OAuth App + GitHub tools. Combined Google smoke, GitHub live/automated tests, Workspace conversational validation, and live vision calls are deferred by Owner.

**PLANNED / DOCUMENTED ONLY (not implemented):** versioned Client API; realtime Voice Runtime; Orb Voice UI; Desktop (`Owiiiii1/JARVIS-Desktop`); Mobile (`Owiiiii1/JARVIS-Mobile`); GitHub merge/file-write/webhooks; proactive assistant / inbox watch. Do not treat these as shipped.

Status vocabulary:

| Status | Meaning |
| --- | --- |
| IMPLEMENTED | Works in production code/runtime |
| PARTIAL | Present, incomplete vs target |
| PLACEHOLDER | UI/route exists, no real domain logic |
| DOCUMENTED ONLY | In `Docs/` only; no tables/code |
| UNUSED / LEGACY | Code or tables exist, not used by Jarvis product |
| UNKNOWN | Not verified |

---

## 1. Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| HEAD | `7a1adc84b7a0601b0da82e162a165fa700f39227` |
| Message | `Document multi-user cabinets, isolated memory, and role-based AI.` |
| Origin | `https://github.com/Owiiiii1/JARVIS.git` |
| Working tree (at audit start) | clean |
| Uncommitted / untracked | none (before this file) |
| Local commits not on origin | none |
| Origin commits not local | none |

Local `main` matched `origin/main` at audit start. This file is the only intended new commit.

`.env` is gitignored and was not staged.

---

## 2. Runtime / stack

| Component | Actual |
| --- | --- |
| OS | Ubuntu 24.04.4 LTS (noble) |
| PHP CLI | 8.5.8 |
| PHP-FPM (this site) | 8.5 (`unix:/run/php/php8.5-fpm.sock`) |
| PHP-FPM also running | 8.3 (other sites; not used by Jarvis nginx) |
| Laravel | 13.30.1 |
| Composer | 2.7.1 |
| Node | 24.14.0 |
| npm | 11.9.0 |
| Database | MySQL 8.0.46, database `jarvis`, user `jarvis_user`, `127.0.0.1:3306` |
| Redis | **not used**. `REDIS_*` exists in `.env`; port 6379 closed; no `redis-cli`; cache/session/queue are database |
| Supervisor | **not installed** |
| Queue driver | `database` (`QUEUE_CONNECTION`) |
| Session driver | `database` |
| Cache driver | `database` (`CACHE_STORE`) |
| Broadcast | `log` |
| Mail | `log` |
| APP_ENV | `production` |
| APP_DEBUG | `false` |

PHP extensions in use for DB: `pdo_mysql`, `mysqlnd`. No Redis PHP usage in this app path.

Composer packages (relevant):

- `owlsolutions/custom-admin-kit` **v0.5.0** (VCS: `https://github.com/Owiiiii1/custom-admin-kit.git`)
- `inertiajs/inertia-laravel` v3.3.2
- `tightenco/ziggy` ^2.6
- `nutgram/nutgram` 4.50.0 — **transitive** via the kit, used by host `TelegramBotManager`

Env keys related to AI/Telegram: **none**. Credentials live in encrypted DB columns, not `.env`.

Do not treat `.env` `APP_KEY`, `DB_PASSWORD`, or stored API/bot tokens as documentable secrets.

---

## 3. Deployment

| Item | Actual | Status |
| --- | --- | --- |
| Domain | `jarvis.owlsolutions.net` | IMPLEMENTED |
| nginx site | `/etc/nginx/sites-available/jarvis.owlsolutions.net` (enabled) | IMPLEMENTED |
| Document root | `/var/www/jarvis/public` | IMPLEMENTED |
| HTTP | 80 → 301 HTTPS | IMPLEMENTED |
| TLS | Let's Encrypt, valid 2026-09-03 → 2026-12-02 | IMPLEMENTED |
| PHP-FPM | `php8.5-fpm.service`, sock `php8.5-fpm.sock` | IMPLEMENTED |
| Queue workers | database worker for `telegram`; guarded by `flock`, restarted from deploy-user crontab | IMPLEMENTED (Telegram) |
| Laravel scheduler | `jarvis:reminders:dispatch` everyMinute; crontab `schedule:run` for `/var/www/jarvis` | IMPLEMENTED |
| Jarvis systemd units | none | — |
| Related host services | `nginx`, `mysql`, `php8.5-fpm` (and unrelated `php8.3-fpm`) | IMPLEMENTED |

Other `/var/www/*` sites share this host. This audit did not change them.

Vite production build is present (`public/build/manifest.json`). `public/build` is gitignored.

---

## 4. Database

Engine: MySQL 8.0.46. **16 tables**. Migrations run: **10**.

### All tables

`ai_provider_settings`, `cache`, `cache_locks`, `customers`, `failed_jobs`, `job_batches`, `jobs`, `migrations`, `order_staff`, `orders`, `password_reset_tokens`, `services`, `sessions`, `staff`, `telegram_bot_settings`, `users`

### Target tables that do **not** exist

| Expected (docs) | Status |
| --- | --- |
| `conversations` | IMPLEMENTED (M3) |
| `messages` | IMPLEMENTED (M3; `parent_message_id` added M4) |
| `topics` | DOCUMENTED ONLY |
| `memories` / `memory_*` | DOCUMENTED ONLY |
| `summaries` | DOCUMENTED ONLY |
| `telegram_groups` | IMPLEMENTED (M11) |
| `telegram_group_participants` | IMPLEMENTED (M11) |
| `channel_identities` | IMPLEMENTED (M2) |
| `user_ai_settings` | IMPLEMENTED (M4) |
| `ai_role_settings` | IMPLEMENTED (M4) |
| `reminders` | IMPLEMENTED (M10) |
| `admin_audit_logs` | DOCUMENTED ONLY |

### Jarvis-relevant tables that exist

#### `users`

- **Status:** IMPLEMENTED (Laravel auth account)
- **Purpose:** login to the admin panel
- **Fields:** `id`, `name`, `email` (unique), `email_verified_at`, `password` (hashed), `remember_token`, timestamps
- **Relations:** none beyond `sessions.user_id`
- **Migration:** `database/migrations/0001_01_01_000000_create_users_table.php`
- **Rows now:** 1
- **Missing vs target:** roles/permissions, last_activity column, user type, cabinet flags

#### `ai_provider_settings`

- **Status:** PARTIAL (credential pool + listModels; not conversation runtime)
- **Purpose:** one row per vendor; encrypted API key, connection check, discovered `available_models`. `is_active` / `active_model` remain for backward compatibility and are **not** the runtime source of truth.
- **Fields:** `id`, `provider` (unique), `label`, `api_key` (encrypted), `is_connected`, `is_active`, `active_model`, `available_models` (json), `last_checked_at`, `last_error`, timestamps
- **Relations:** none (referenced by `ai_role_settings.provider` as a string key)
- **Migration:** `2026_07_04_170000_create_ai_provider_settings_table.php`
- **Rows now:** 3 (`openai`, `anthropic`, `gemini`). All `is_connected=0`, `is_active=0`, no `active_model`, no stored API keys

#### `ai_role_settings`

- **Status:** IMPLEMENTED (M4 runtime source of truth)
- **Purpose:** independent Owner Conversation / Owner Analysis / Default User Conversation configs
- **Fields:** `id`, `role_key` (unique), `provider`, `model`, `system_prompt`, `parameters` (json), `is_enabled`, timestamps
- **No API keys**
- **Migration:** `2026_09_03_200000_create_ai_role_settings_table.php`
- **Rows now:** 3, all `is_enabled=0`, no provider/model until owner configures them

#### `user_ai_settings`

- **Status:** IMPLEMENTED (M4 General Prompt only)
- **Fields:** `id`, `user_id` (unique FK), `general_prompt`, `overrides` (json, unused), timestamps
- **Migration:** `2026_09_03_200100_create_user_ai_settings_table.php`
- **Rows now:** 0

#### `telegram_bot_settings`

- **Status:** PARTIAL (bot credentials + webhook admin)
- **Purpose:** single-row bot config
- **Fields:** `id`, `bot_token` (encrypted), `bot_username`, `webhook_url`, `webhook_secret` (encrypted), `is_webhook_set`, `is_connected`, `last_checked_at`, `last_webhook_set_at`, `last_error`, timestamps
- **Relations:** none
- **Migration:** `2026_07_21_150000_create_telegram_bot_settings_table.php`
- **Rows now:** 1. Token present; `bot_username=owl_jarvis_bot`; `is_connected=1`; `is_webhook_set=1`

#### Framework tables

| Table | Purpose | Migration |
| --- | --- | --- |
| `sessions` | session driver | users migration |
| `password_reset_tokens` | Laravel reset tokens | users migration — **no reset routes** |
| `cache`, `cache_locks` | cache driver | `0001_01_01_000001_create_cache_table.php` |
| `jobs`, `job_batches`, `failed_jobs` | queue driver | `0001_01_01_000002_create_jobs_table.php` — Telegram crontab worker + systemd `jarvis-queue.service` (memory,default) |

---

## 5. Legacy CRM (kit demo)

Tables: `customers`, `services`, `staff`, `orders`, `order_staff` — all **0 rows**.

Migrations: `2026_07_05_100000` … `100400`.

Code still in the **host app** (not only vendor):

- Models: `Customer`, `Service`, `Staff`, `Order`
- Controllers: `CustomersController`, `ServicesController`, `StaffController`, `OrdersController`
- Inertia pages: `resources/js/Pages/{Customers,Services,Staff,Orders}/Index.jsx`

**Not** in current nav or `routes/owl-admin-pages.php`. Those URLs 404.

Classification:

| Question | Answer |
| --- | --- |
| A. Required by custom-admin-kit runtime? | **No.** Kit v0.5.0 is an admin shell. CRM was copied into the host as the `admin` preset. Kit does not boot these models. |
| B. Used by Jarvis product? | **No.** |
| C. Demo / leftover? | **Yes.** |

**Recommendation (do not delete in this audit):** treat as UNUSED / LEGACY. Safe cleanup later: drop routes (already gone), delete host models/controllers/pages, then a migration to drop empty tables. Confirm no kit upgrade script re-publishes them. Calendar was kept as a personal-schedule **placeholder**, not CRM.

---

## 6. Authentication

| Topic | Status | Fact |
| --- | --- | --- |
| Admin login | IMPLEMENTED | `GET/POST /` → `AuthenticatedSessionController`; guest `/login` redirects to `/` |
| Session | IMPLEMENTED | Laravel `web` guard, `User` model, hashed password |
| Logout | IMPLEMENTED | `POST /logout` |
| User model | PARTIAL | `name`, `email`, `password` only. No roles, permissions, types |
| Roles / permissions | DOCUMENTED ONLY | Any authenticated user is a full admin (`AdminRouteMiddleware` = `web` + `auth`) |
| Admin vs cabinet | DOCUMENTED ONLY | One auth context. Login always goes to `/dashboard` |
| User cabinet auth | DOCUMENTED ONLY | No cabinet routes |
| Impersonation | DOCUMENTED ONLY | No code |
| Password change (logged-in) | IMPLEMENTED | `/profile` + `PUT /password`; Settings → Users can set another user’s password (hash) |
| Password reset email | UNUSED | `password_reset_tokens` table exists; `password.request` route **absent**; login passes `canResetPassword=false` |
| Email verification | UNUSED | `owl-admin.email_verification` false |

Passwords are hashed. User Card / Open Cabinet do not exist.

---

## 7. Admin panel

Shell: custom-admin-kit + host Inertia pages. Branding: navy/amber, `OWL_ADMIN_BRAND=Jarvis`.

Nav (actual): Home, Calendar, Statistics → Logs, Settings. Footer: Powered by OwlSolutions.

| Page | Route | Backend | UI | Status |
| --- | --- | --- | --- | --- |
| Login | `/` | `AuthenticatedSessionController` | `Auth/Login.jsx` | IMPLEMENTED |
| Dashboard | `/dashboard` | closure Inertia | `Dashboard.jsx` | IMPLEMENTED (links only) |
| Calendar | `/calendar` | `CalendarController` (`events: []`) | `Calendar/Index.jsx` | PLACEHOLDER |
| Settings | `/settings?tab=` | `SettingsController` | `Settings/Index.jsx` | PARTIAL |
| Settings General | `tab=general` | — | `GeneralPanel.jsx` | PLACEHOLDER |
| Settings Users | `tab=users` | `Settings\UserController` CRUD | `UsersPanel.jsx` | PARTIAL (admin accounts, not User Card) |
| Settings AI | `tab=ai` | `AiSettingsController` | `AiPanel.jsx` | PARTIAL (one global provider) |
| Settings App | `tab=app` | redirect `/app-settings` → tab | `AppPanel.jsx` | PLACEHOLDER |
| Settings Telegram | Integrations tab (`?tab=telegram` aliases here) | `TelegramSettingsController` | `TelegramPanel.jsx` inside Integrations | IMPLEMENTED (token/webhook) |
| Profile | `/profile` | `ProfileController` | `Profile/Edit.jsx` | IMPLEMENTED (admin self) |
| Logs | `/statistics/logs` | closure | `Statistics/Logs.jsx` | PLACEHOLDER |
| AI settings alias | `/ai-settings` | redirect to settings tab | — | IMPLEMENTED redirect |
| Users (dedicated) | — | — | — | DOCUMENTED ONLY |
| User Card | — | — | — | DOCUMENTED ONLY |
| Telegram Groups | `/telegram-groups` | `TelegramGroupController` | `TelegramGroups/Index.jsx`, `Show.jsx` | IMPLEMENTED (M11, owner-only, persist-only) |
| Chats / Topics | — | — | — | DOCUMENTED ONLY |
| Integrations settings | — | — | — | DOCUMENTED ONLY / MISSING FROM DOCS |

Settings Users copy: “Accounts allowed to sign in to the **admin panel**.” Creating a user grants the same admin access. No User Card, chats, topics, AI-per-user, impersonation.

AI panel: Provider Credentials (keys / listModels) plus three independent AI configuration blocks. Runtime does **not** use `ai_provider_settings.is_active`.

---

## 8. AI implementation

Path: `app/Services/Ai`

| Piece | Status | Fact |
| --- | --- | --- |
| Contract | IMPLEMENTED | `AiProviderClient`: `listModels` + `supportsChat` + `chat(AiChatRequest): AiChatResponse` |
| Manager | IMPLEMENTED | `AiProviderManager` maps `openai` / `anthropic` / `gemini` |
| Clients | IMPLEMENTED | OpenAI Responses API (`/v1/responses`, fallback `/v1/chat/completions`); Anthropic `/v1/messages`; Gemini `generateContent` |
| Credentials | PARTIAL | Encrypted `ai_provider_settings.api_key`. No keys stored at M4 deploy |
| Role-based config | IMPLEMENTED | `ai_role_settings` — `owner_conversation`, `owner_analysis`, `user_conversation` |
| Resolver | IMPLEMENTED | `AiConfigurationResolver`: owner → owner_conversation; user → user_conversation; analysis separate |
| System / user prompt | IMPLEMENTED | Platform prompt per role in DB; User General Prompt in `user_ai_settings` |
| LLM chat | IMPLEMENTED | Conversation engine via `AiChatGateway`; Telegram DM uses conversation configs only |
| Admin UI | IMPLEMENTED | Settings → AI: credentials + three role blocks |
| Tests | IMPLEMENTED | Fake gateway + Http::fake; no live billable calls |

Runtime source of truth: **`ai_role_settings`**. `is_active` is unused by conversation runtime. Owner Analysis is configurable and is **not** called from personal DM.

`HandleInertiaRequests` badge reads enabled Owner Conversation config + connected provider credentials.

---

## 9. Telegram

| Piece | Status | Fact |
| --- | --- | --- |
| Library | PARTIAL | Nutgram 4.50.0 + HTTP fallback in `TelegramBotManager` |
| Settings UI / API | PARTIAL | save token, `getMe`, set/remove webhook |
| Transport | PARTIAL | **Webhook** designed (`POST /telegram/webhook`). No long polling |
| Webhook handler | PLACEHOLDER | `TelegramWebhookController` checks secret header, returns `{ok:true}`. **No persist, no reply, no Nutgram handlers** |
| DM | DOCUMENTED ONLY | |
| Groups | DOCUMENTED ONLY | |
| Message persistence | DOCUMENTED ONLY | |
| User mapping / `channel_identities` | DOCUMENTED ONLY | |
| Queues | UNUSED | |
| Runtime config | PARTIAL | DB says token + webhook set for `@owl_jarvis_bot`. Incoming updates are acknowledged only |

This audit did **not** call Telegram API or change settings.

---

## 10. Conversations / messages

| Piece | Status |
| --- | --- |
| Models | DOCUMENTED ONLY |
| Tables | DOCUMENTED ONLY |
| Conversation Engine | DOCUMENTED ONLY |
| Context builder / recent window | DOCUMENTED ONLY |
| Persistence | DOCUMENTED ONLY |
| API | DOCUMENTED ONLY |

No `app/Services` conversation classes. No controllers for chats.

---

## 11. Memory Engine

| Piece | Status |
| --- | --- |
| topics, memories, summaries | DOCUMENTED ONLY |
| classification / extraction | DOCUMENTED ONLY |
| retrieval | DOCUMENTED ONLY |
| embeddings / vector | DOCUMENTED ONLY |
| Analysis AI jobs | DOCUMENTED ONLY |

---

## 12. Users / Cabinet

| Feature | Status | Fact |
| --- | --- | --- |
| Users list (admin settings tab) | PARTIAL | name, email, created_at; CRUD |
| User Card | DOCUMENTED ONLY | |
| User profile (cabinet) | DOCUMENTED ONLY | Admin `/profile` is self-edit for the logged-in admin |
| User General Prompt | IMPLEMENTED | Owner: Profile; User: `/cabinet/ai-settings`. Self-only |
| User AI override | DOCUMENTED ONLY | No per-user model override |
| User conversations / topics | PARTIAL | Personal conversations exist; topics later |
| Cabinet | IMPLEMENTED | Chat UI `/cabinet/chats/{id}`, New chat, rename, General Prompt |
| Cabinet login | IMPLEMENTED | Role-based redirect after login |
| Chat list / new chat / history | DOCUMENTED ONLY | |
| Impersonation | DOCUMENTED ONLY | |

---

## 13. API

There is **no** `routes/api.php`. `bootstrap/app.php` registers `web` + `console` + `/up` only.

| Route | Purpose | Status |
| --- | --- | --- |
| `GET /up` | Laravel health | IMPLEMENTED |
| `GET /owl-admin/health` | kit JSON `{status, kit, preset}` | IMPLEMENTED |
| `POST /telegram/webhook` | ACK webhook | PLACEHOLDER |
| `GET/PUT storage/{path}` | framework local disk | IMPLEMENTED (framework) |

| Target API | Status |
| --- | --- |
| Auth API (Sanctum/JWT) | DOCUMENTED ONLY |
| conversations / messages | DOCUMENTED ONLY |
| users (public) | DOCUMENTED ONLY |
| voice / mobile / desktop | DOCUMENTED ONLY |

Kit `composer.json` *suggests* Sanctum; it is **not** installed.

---

## 14. Frontend

| Item | Actual |
| --- | --- |
| Stack | React 18 + Inertia (`@inertiajs/react` 2.3) + Vite 8 + Tailwind 4 |
| Blade | `resources/views/app.blade.php` root only |
| Kit | custom-admin-kit v0.5.0 host pages |
| Ziggy | yes |
| Build | `public/build/manifest.json` present (production) |
| Pages in use | Auth/Login, Dashboard, Calendar, Settings/*, Profile/Edit, Statistics/Logs |
| Pages unused | Customers, Orders, Staff, Services, AppSettings/Index (legacy/alias) |

---

## 15. Tests

Command: `php artisan test`

| Result | Value |
| --- | --- |
| Outcome | **passed** |
| Tests | 2 |
| Assertions | 2 |
| Duration | ~100 ms |
| Failures | none |

Suite:

- `Tests\Unit\ExampleTest::test_that_true_is_true`
- `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (`GET /` → 200 login)

No domain tests for AI, Telegram, auth policies, or memory.

---

## 16. Documentation audit

Other `Docs/` files describe **target** architecture. `PROJECT.md` already says they are not the running Core. They are accurate as intent, not as implementation.

| Document | Accurate (as target) | Outdated | Missing implementation | Contradictions |
| --- | --- | --- | --- | --- |
| `PROJECT.md` | Yes — multi-user + groups + roles as goals | Residual “personal assistant” singular tone | Entire Core | None vs latest ADRs |
| `ARCHITECTURE.md` | Yes as target | — | All Core modules | — |
| `DEVELOPMENT_PHASES.md` | Yes; Phase 1 still Telegram-first | Phase 1 narrative still one “пользователь” in Telegram | Phase 1+ not built | First-Telegram-as-owner is onboarding, not single-user — OK |
| `MEMORY_ARCHITECTURE.md` | Target only | — | 100% | — |
| `CONVERSATION_ENGINE.md` | Target only | — | 100% | — |
| `CHANNELS.md` | Target only | — | Adapter beyond webhook ACK | — |
| `TELEGRAM_GROUPS.md` | Matches latest group ADRs | — | 100% | Code has no groups; docs do not claim otherwise |
| `USERS_AND_CABINET.md` | Matches latest user ADRs | — | 100% | Settings Users ≠ User Card (docs vs **code**) |
| `DATABASE.md` | Conceptual | — | Most entities | — |
| `AI_PROVIDER_ARCHITECTURE.md` | Role-based target | — | Roles, prompts, LLM | **Code/UI is one global active model** — opposite of ADR-013 |
| `API.md` | Target | — | No public API | — |
| `ROADMAP.md` | Target phases | — | Phase 1 not started in code | — |
| `DECISIONS.md` ADR-001–021 | Accepted target decisions | — | All post-010 product ADRs | Implementation predates 011–021 |
| `VOICE_ARCHITECTURE.md` | Target Phase 3 | — | 100% | — |
| `HUMAN_LIKE_ASSISTANT.md` | Target Phase 4 | — | 100% | — |
| `CURRENT_STATE.md` | This file | — | — | — |

### Multi-user vs docs

Docs **were updated** to multi-user (ADR-016–021). They no longer mandate a single-owner Core.

**Code is still single-admin-panel:** one `users` table, every login is admin, no isolation layer, no per-user memory/chats/prompts.

Residual singular wording in Phase 1 Telegram stories is narrative, not an ADR conflict.

### Telegram Groups vs docs

Docs (ADR-011–015, `TELEGRAM_GROUPS.md`) are internally consistent. **Zero** implementation.

### Conversation / Analysis AI vs docs

Docs: roles + inheritance. **Code (M4):** `ai_role_settings` is the runtime source of truth. `ai_provider_settings.is_active` is unused by conversation runtime.

---

## 17. Requirements not yet reflected in documentation

Approved product requirements that are **not** specified (or only partly implied) in existing `Docs/` files. Snapshot only — no doc rewrite in this audit except this section.

### Multi-user (restate for tracking)

Already largely specified in `USERS_AND_CABINET.md`. Keep listed so this snapshot is complete:

Each user: account, cabinet, Profile, Chat, many ChatGPT-style chats, own history, Topics, Long-Term Memory, General Prompt, optional AI override.

Admin: Users table, User Card, edit profile, password reset/change, Chats, Topics, AI Settings, Open Cabinet via impersonation.

### Google Calendar (owner / tools layer)

Future tools/actions for the **primary Jarvis owner**:

- read calendars;
- search events;
- free-busy / schedule check;
- create / update / delete events;
- multiple calendars if needed.

### Gmail (owner / tools layer)

- search mail;
- read messages and threads;
- new-mail awareness;
- create drafts;
- reply;
- send;
- labels if needed.

All **write** actions must go through an integrations/tools layer, not channel adapters or UI.

**Not implemented. Not documented** in `Docs/` besides this file. Do not add Google API now.

### External Integrations Settings

Target Admin: **Settings → Integrations**.

Providers at minimum: Telegram, Google, ElevenLabs, later others.

Per integration UI: provider, connection status, connected account, scopes/permissions, `connected_at`, last successful sync/use, reconnect, disconnect, diagnostics.

Google: OAuth 2.0. Access/refresh tokens **not** stored plaintext.

New providers without changing Jarvis Core.

**Not implemented. Not in existing architecture docs** (Telegram today is a Settings tab, not a generic integrations registry).

---

## 18. Component status matrix

| Component | Status |
| --- | --- |
| Laravel + nginx + SSL + MySQL | IMPLEMENTED |
| Admin kit shell + login | IMPLEMENTED |
| Settings Users CRUD | PARTIAL |
| AI provider credentials + listModels | PARTIAL |
| AI role configurations runtime | IMPLEMENTED |
| Telegram token + webhook admin | PARTIAL |
| Telegram webhook ingest | IMPLEMENTED (pairing + conversations + AI DM) |
| Calendar | PLACEHOLDER |
| Logs | PLACEHOLDER |
| Settings General / App | PLACEHOLDER |
| Conversation Engine | PARTIAL (personal DM + recent window + tools: reminder, history search, owner project context) |
| Memory Engine | IMPLEMENTED (personal v1; group knowledge is a separate table, not `memories`; no vector DB) |
| Projects | IMPLEMENTED (owner-only; `get_project_context` may include bounded derived group knowledge) |
| Telegram Groups module | IMPLEMENTED (discovery, raw persist, admin messenger, outbound, manual Group Analysis) |
| Role-based Conversation/Analysis AI | IMPLEMENTED (conversation runtime + Analysis AI background memory jobs) |
| User Cabinet | IMPLEMENTED |
| Impersonation / ownership policies | DOCUMENTED ONLY |
| Public/mobile API | DOCUMENTED ONLY |
| Voice / ElevenLabs | DOCUMENTED ONLY |
| Google Calendar / Gmail | DOCUMENTED ONLY / MISSING FROM DOCS |
| Integrations registry | DOCUMENTED ONLY / MISSING FROM DOCS |
| Queue workers / scheduler | IMPLEMENTED (Telegram queue worker + analysis/memory/default systemd worker + reminder scheduler) |
| Redis | UNUSED |
| CRM customers/orders/staff/services | UNUSED / LEGACY |

---

## What works now

- Production site on HTTPS with PHP 8.5-FPM and MySQL.
- Admin login at `/`, session auth, logout, self profile/password change.
- Admin CRUD of other **admin** users (Settings → Users).
- Dashboard, branded layout, locale switch (session `en|ru|uk`).
- AI settings: provider credentials (keys / listModels) and three independent role configurations. Runtime uses `ai_role_settings`, not `is_active`. No provider keys stored at M4 deploy; role configs exist but are disabled until the owner sets them.
- Telegram settings: encrypted token, getMe, set/remove webhook. DB reports bot `@owl_jarvis_bot` connected and webhook set.
- Webhook: pairing, conversations/Chat Selector, and Conversation AI for paired normal text (after role configs are enabled).
- Health endpoints `/up`, `/owl-admin/health`.
- Frontend production build.
- Example PHPUnit tests pass (2).

---

## What exists but is incomplete

- Telegram: pairing + chat selector work; AI replies after owner enables a conversation config and connects provider credentials.
- AI: chat contract implemented; no keys connected at M4 deploy; Analysis jobs and tools not built.
- Users: identity + cabinet catalog + General Prompt; no User Card / impersonation.
- Calendar / Logs / General / App settings: routes and UI shells.
- Queue/cache/session tables without workers or Redis.
- Password reset table without routes.

---

## Legacy / cleanup candidates

- Host CRM models, controllers, Inertia pages, empty tables (`customers`, `services`, `staff`, `orders`, `order_staff`).
- Unused `Pages/AppSettings/Index.jsx` (redirect covers `/app-settings`).
- `php8.3-fpm` on the host (not Jarvis; do not touch unless separately requested).

Do **not** delete in this audit.

---

## Missing compared to target architecture

- Jarvis Core: Conversation Engine, Memory Engine, context package, prompt hierarchy.
- Multi-user cabinets, ownership, impersonation, audit logs.
- `conversations` / `messages` / topics / memories / group tables.
- Telegram adapter: normalize, persist DM/groups, auto-register groups, admin group chat, outbound via adapter.
- Role-based AI (`conversation` / `analysis`) and per-user overrides.
- Analysis jobs, group knowledge vs personal memory.
- Settings → Integrations (Telegram/Google/ElevenLabs registry, Google OAuth).
- Google Calendar and Gmail tools.
- Mobile / Desktop / Voice / public API.
- Queue workers and scheduler for async analysis.
- Domain tests.

The running product is an **admin shell with AI/Telegram credential screens**. It is not yet Jarvis Core.

---

## Requirements approved after audit

The snapshot **above** stays historical. Nothing in this section is implemented unless a later audit says so.

Approved target (2026-09-03, after this file’s first version):

- Roles `owner` / `user`; owner is a normal `users` row, not a hardcoded id.
- Owner access_code **`2000`** for Telegram pairing only — not a web password.
- Unique human-readable `access_code` per user; no Telegram auto-registration.
- Web: owner → Admin Panel; user → Cabinet; backend authorization.
- Telegram webhook + Nutgram pairing, then Conversation AI greeting; wrong code never calls AI.
- Conversation AI ≠ Analysis AI; Google/Gmail/ElevenLabs via Tool Layer, owner-only.
- Telegram Groups owner-only; memory isolated per user.

Canonical write-up of later target architecture (spaces, Chat Selector, reminders, projects, group intelligence): other `Docs/` files and ADR-032–045. This file remains a **code snapshot**, not the target.

---

## Implementation progress

### Milestone 0 — COMPLETED (2026-09-03)

Host CRM leftover removed. No Identity/Telegram/AI-runtime work.

- Deleted host models/controllers/Inertia pages: customers, services, staff, orders.
- New migration `2026_09_03_162500_drop_legacy_crm_tables` dropped empty `order_staff`, `orders`, `customers`, `services`, `staff` after a 0-row check.
- Kept `users`, `ai_provider_settings`, `telegram_bot_settings`, framework tables, Calendar/Logs placeholders, Settings, Admin login, kit.
- Added `tests/Feature/BaselineTest.php`. PHPUnit no longer forces sqlite (`pdo_sqlite` is not installed on this PHP).
- `npm run build` succeeded. Telegram webhook route and stored bot settings unchanged.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 1 — COMPLETED (2026-09-03)

Identity foundation: `users.role`, `access_code`, `status`, `timezone`; existing admin promoted to owner (`2000`, `Europe/Rome`); `AccessCodeGenerator`; `UserCapabilities`; owner-only admin middleware; `/cabinet` shell; login redirect by role; Settings → Users catalog columns; disabled-user blocking; production-safe tests on `jarvis` DB.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 2 — COMPLETED (2026-09-03)

Telegram webhook wired to Nutgram; `channel_identities` table; pairing by access code (`/start`, text code, no AI); owner unlink + access code regenerate in Settings → Users; group pairing blocked; production-safe tests.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 3 — COMPLETED (2026-09-03)

Conversations/messages persist; `active_conversation_id` FK; default `Основной`; Telegram Chat Selector (list/select/new/current); Cabinet shows the same catalog; Settings → Users chat/message counts. AI not invoked. Chat Selector originally planned as Milestone 6.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 4 — COMPLETED (2026-09-03)

Three independent AI configurations (`ai_role_settings`); User General Prompt; provider `chat()` for OpenAI/Anthropic/Gemini; ConversationContextBuilder (current chat only); Telegram DM + pairing greeting call Conversation AI; Analysis config exists but is not used in DM. Runtime source of truth is `ai_role_settings`, not `is_active`. No live provider keys were stored at deploy; AI replies after the owner connects a provider and enables role configs.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Web Cabinet Chat — COMPLETED (2026-09-03)

User Cabinet messenger UI. `/cabinet` ensures `Основной` and redirects to `/cabinet/chats/{id}`. Web and Telegram share conversations/messages and `ConversationTurnService`. Ownership 404. Web idempotency: `channel=web` + client UUID.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 10 — Reminder Engine + first Conversation Tool — COMPLETED (2026-09-03)

`reminders` table; channel-neutral `ReminderService` / `ReminderDeliveryService`; Tool Layer (`create_reminder`); Gemini function calling; multi-tool loop (max 5); current local time + timezone injection; no Telegram identity → reminder not created; production scheduler + cron; Telegram delivery `⏰ Напоминание: {text}` without an AI turn; one-time only (recurrence later). Settings → Users shows `reminders_count`. Manual owner smoke awaiting.

Production hotfix: Telegram webhook only validates and queues an update, then returns immediately. A dedicated database queue worker performs Gemini calls. Gemini 3 tool turns preserve `thoughtSignature`. Failed/pending historical user turns are excluded from later AI context, preventing a stale reminder request from authorizing tools for messages such as «Ты тут?». `create_reminder` is idempotent per source message.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 12 — Structured Memory / Memory Engine v1 — COMPLETED (2026-09-03)

Personal derived memory on production MySQL. Raw `messages` remain immutable source of truth. Owner Analysis AI extracts topics/memories and writes incremental conversation summaries in the background (`memory` queue). `PersonalMemoryRetriever` is `user_id`-scoped, relational, bounded. Cross-chat is summary-first; raw other chats only via `search_conversation_history`. Settings → Users → Memory is owner-only read-only diagnostics. Group knowledge and Projects are not in this milestone. Vector DB is not installed.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 13 — Projects — COMPLETED (2026-09-03)

Owner-only `projects` with pivots to conversations, topics, memories, and (from M11) telegram groups. Archive/restore. Admin CRUD + attach/detach. `get_project_context` is tool-driven and not injected into the default prompt. Group attach is relation-only. From M14, context may include bounded ACTIVE group-derived knowledge; never raw group history. No automatic classification or seeded JARVIS/YFS/RTS rows.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 11 — Telegram Groups — COMPLETED (2026-09-03)

Owner-only Telegram groups. First group/supergroup update auto-registers `telegram_groups` + `kind=group` conversation (administrative `user_id` = owner). Raw messages persist on the existing `messages` table. Participants are Telegram identities, not Jarvis Users. Default mode persist-only: no Conversation AI, no personal memory jobs, no auto-reply. Admin list + messenger + timezone + outbound via existing `TelegramBotManager`. `project_groups` attach/detach without copying raw. Channels ignored. Privacy mode is a manual BotFather prerequisite; Cursor does not change it.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 14 — Group Analysis — COMPLETED (2026-09-04)

Async Owner Analysis AI over Telegram group raw history. Derived Summary / Decision / Task / Event-Fact live in `telegram_group_knowledge` with provenance and revisions. Never writes personal `memories`, `user_profiles`, or personal topics. Manual Admin/CLI runs (queue `analysis`); no auto analysis on inbound group messages. Date ranges use group timezone (fallback owner). Large ranges chunk/reduce. Empty ranges skip the LLM. `get_project_context` may return bounded ACTIVE derived group knowledge; `ConversationContextBuilder` does not.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 15 — Group Knowledge Search — COMPLETED (2026-09-04)

Owner-only `search_group_knowledge` (capability `group_analysis`) for Telegram/Web personal chat. Channel-neutral `GroupKnowledgeSearchService`: derived-first ACTIVE knowledge, bounded raw fallback, per-group timezone ranges, project filter via `project_groups`, participant display-name search. Missing/stale analysis may queue the existing M14 job without blocking the turn. Default personal prompt still contains zero group knowledge/raw. No migration. No Admin search UI. No Vector DB. Manual live smoke deferred by Owner.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 16 — Integration Framework — COMPLETED (2026-09-04)

Owner Integration Layer: code `IntegrationRegistry` (google / telegram / elevenlabs), `integration_accounts` with encrypted credentials, `tool_execution_logs`, `ToolExecutionService`, confirmation policy skeleton, Settings → Integrations. Telegram card reuses `telegram_bot_settings` and does not copy the bot token. Google OAuth, Calendar/Gmail tools, and ElevenLabs API are not implemented. Existing Core tools still run through the wrapper. Production `integration_accounts` = 0.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 17 — Google OAuth — COMPLETED (2026-09-04)

Owner Google Authorization Code + PKCE. Callback path `/integrations/google/callback`. Tokens encrypted on `integration_accounts`. Identity scopes only (`openid email profile`). Refresh via `GoogleCredentialService` (no page-load Google calls). Connected ≠ Calendar/Gmail tools. Env client id/secret were not set by Cursor; Integrations card is Not configured until Owner adds Google Cloud credentials. Production `integration_accounts` remains 0.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 18 — Google Calendar — COMPLETED (2026-09-04)

Owner Calendar tools over live Google (no local event mirror, no sync cron/webhook). Incremental OAuth adds `https://www.googleapis.com/auth/calendar` without dropping identity scopes. Tokens still only via `GoogleCredentialService`. Destructive delete uses persisted `tool_confirmations`. Reminder Engine remains separate. Live Google Calendar smoke deferred by Owner until Google integration milestones are complete. Production `integration_accounts` remains 0.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 19 — Gmail — COMPLETED (2026-09-04)

Owner Gmail tools over live Gmail (no local mailbox mirror, no polling, no watch/historyId). Incremental OAuth adds `gmail.readonly` + `gmail.compose` + `gmail.modify` without dropping identity or Calendar scopes. Tokens still only via `GoogleCredentialService`. Send always requires persisted `tool_confirmations` (one-time). Draft ≠ send. Attachments metadata-only on read; outbound attachments out of scope. Combined live Google smoke still deferred. Production `integration_accounts` remains 0.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 20 — Combined Google smoke — DEFERRED BY OWNER

Validation only. Not a coding milestone. Live Google still not exercised.

### Milestone 21 — GitHub Integration — IMPLEMENTED, NOT LIVE-VALIDATED (2026-09-04)

Owner GitHub OAuth App + tools over live GitHub API (no local repo mirror, no shell git, no webhook/polling). Scopes `repo` + `read:org`. Credentials encrypted on `integration_accounts`. Token only via `GitHubCredentialService`. HTTP only via `GitHubApiService`. Read: repos/branches/commits/compare/file/code search/issues/PRs/workflow runs. Write: create issue, comment, create branch, create PR. No merge/delete/force/file-write/workflow edit. Capability `github` (owner). Normal users do not receive GitHub tools. Integrations card: Connect / Reconnect / Disconnect; Not configured without env. Env client id/secret were not set by Cursor. Automated tests not run (Owner decision). Live GitHub not connected. Combined Google smoke still deferred.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 22 — Owner Web Workspace — IMPLEMENTED, NOT VALIDATED (2026-09-04)

Owner Personal Workspace at `/jarvis`. Owner-only. Same `conversations`/`messages` and `ConversationTurnService` as Telegram/Cabinet. Channel remains `web`. Owner login → `/jarvis`. Owner `/cabinet` → `/jarvis`. Admin stays `/dashboard` with Open Jarvis. Voice Mode is a CSS placeholder only. No new tables. Automated tests not run. No live AI/Google/GitHub send.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Milestone 22.1 — Workspace images + copyable artifacts — IMPLEMENTED, NOT VALIDATED (2026-09-04)

Private `message_attachments` (generic kind, images first). Multipart send on Workspace through existing `ConversationTurnService`. Provider-neutral `AiContentPart`; Gemini `supportsVision=true` with current-turn inline images only. OpenAI/Anthropic return `vision_not_supported`. SafeMarkdown distinguishes fenced code vs `artifact` copy blocks. No Telegram photo ingestion. No live vision calls. Automated tests not run.

See [Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md).

### Planned after M22.1 — DOCUMENTED ONLY

Voice Runtime / Orb; Desktop Tauri; Mobile Flutter; GitHub merge/file-write/webhooks; proactive assistant. Do not treat as shipped.

See [ROADMAP.md](ROADMAP.md), [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md).
