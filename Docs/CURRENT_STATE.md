# Jarvis — current implementation snapshot

**Date:** 2026-09-06 (Core Reliability Cleanup)
**Host path:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git

This file is a **runtime snapshot**. If it disagrees with older milestone prose, this file and the code win.

### Status vocabulary

| Status | Meaning |
| --- | --- |
| IMPLEMENTED | In production code |
| MANUAL PASS | Owner confirmed in production |
| MANUAL PARTIAL | Owner confirmed part of the flow |
| IMPLEMENTED / NOT VALIDATED | Code exists; not Owner-confirmed |
| LIVE BUG | Code exists; Owner reports it does not work as expected |
| DEFERRED | Explicitly not current work |
| CANCELLED | Will not be built |

---

## Manual production validation

**PASS — core ordinary user (M25U.2):**

- Owner created an ordinary user via Admin
- login works
- `/chat` works
- normal test requests work

**PASS — Owner Workspace (earlier 2026-09-04):**

- image upload + Gemini vision
- persistent text-file upload / Storage retrieval through chat
- Gemini Google Search web research

**PASS — Voice pipeline (M23–M24.1.1); current PTT UI implemented:**

- Voice mode starts
- microphone permission/session starts
- hold-to-talk recording; release sends the turn
- Gemini STT
- Jarvis generates a reply
- ElevenLabs TTS plays audio
- each user can select a personal TTS voice

The former hands-free «Диалог» VAD capture was removed from Рация. **Диалог Beta** is a new parallel Web mode (ElevenLabs realtime), **IMPLEMENTED / NOT VALIDATED**, default off (`ELEVENLABS_REALTIME_ENABLED=false`). Рация remains the default and is not removed.

**PARTIAL — M25U.3:**

- Onboarding / «Знакомство» **appears** (Owner)
- Full onboarding conversation / completion / profile update: **not** MANUAL PASS
- Reminders panel / Reminders 2.0: **MANUAL PASS for confirmed live core flow** (Web Push, Reminder Center, basic user flow). Not exhaustive DST/recurrence/multi-device MANUAL PASS.
- `create_reminder` without Telegram: covered by that same live core flow
- Phase B.2 Tasks / Notification Center / briefs / proactive: **IMPLEMENTED / NOT VALIDATED**

**Not claimed:** A/B IDOR campaign; combined Google/GitHub live campaign; Tavily; `fetch_web_page` as a distinct Owner check; screenshot purge; destructive Storage delete.

---

## 1. Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| HEAD | `main`, aligned with `origin/main` after Core Reliability Cleanup |
| Origin | `https://github.com/Owiiiii1/JARVIS.git` |

Production checkout is the GitHub source of truth. Gemini STT request-shape and bounded ElevenLabs voice fallback are committed. Laravel Boost is require-dev tooling in a separate commit. `.env` stays gitignored.

---

## 2. Runtime / stack

| Component | Actual |
| --- | --- |
| OS | Ubuntu 24.04 LTS |
| PHP CLI / FPM | 8.5.8 (`php8.5-fpm.sock`) |
| Laravel | 13.30.1 |
| Composer | 2.7.x |
| Database | MySQL 8.0, database `jarvis` |
| Redis | **not used** (cache/session/queue = database) |
| Queue | `database` |
| APP_ENV | `production` |
| APP_DEBUG | `false` |

Composer (relevant): `owlsolutions/custom-admin-kit` v0.5.0, Inertia, Ziggy, Nutgram (transitive via kit).

AI / Telegram / ElevenLabs credentials: encrypted DB columns, not `.env`. Do not document secrets.

---

## 3. Deployment

| Item | Actual |
| --- | --- |
| Domain | `jarvis.owlsolutions.net` |
| nginx | `/var/www/jarvis/public`, HTTP→HTTPS |
| TLS | Let's Encrypt |
| Scheduler | crontab `schedule:run`; `jarvis:reminders:dispatch` every minute; `jarvis:tasks:dispatch` / `jarvis:proactive:dispatch` every 5 minutes; `jarvis:briefs:dispatch` every minute; attachment purge hourly; `jarvis:voice:cleanup-temp` every 5 minutes; `jarvis:reliability:recover-stale` every 15 minutes; fallback `queue:work` for `analysis,memory,default` (`--timeout=180`). Long-running worker: `jarvis-queue.service` same queues. |
| Telegram queue | deploy-user crontab `flock` worker (host-specific) |

Vite production build is generated on deploy (`public/build` gitignored).

---

## 4. Database

Engine: MySQL. CRM tables were dropped (M0). App migrations listed as Ran.

### Tables (product)

Includes identity/conversation/memory/integration/voice tables plus `reminders`, `reminder_deliveries`, `reminder_occurrences`, `push_subscriptions`, `tasks`, `jarvis_notifications`, `user_productivity_settings`.

See [DATABASE.md](DATABASE.md).

---

## 5. Product surfaces

| Surface | Path | Status |
| --- | --- | --- |
| Login | `/` | IMPLEMENTED |
| Owner Workspace | `/jarvis` | PRIMARY, MANUAL PASS (selected flows) |
| User Workspace | `/chat` | MANUAL PASS (core) |
| `/cabinet` | compatibility redirects + leftover JSON | LEGACY |
| Admin | `/dashboard`, `/settings/*` | IMPLEMENTED |
| Voice | workspace Text/Voice + `/…/voice/sessions/*` | MANUAL PASS |
| Storage page | `/jarvis/storage` Owner-only | IMPLEMENTED |
| Projects | `/projects` Owner | IMPLEMENTED |
| Telegram Groups | `/telegram-groups` Owner | IMPLEMENTED |
| Desktop | — | CANCELLED |
| Mobile | — | DEFERRED |
| Versioned Client API | — | DEFERRED |

Frontend: `resources/js/personal-workspace/PersonalWorkspace.jsx` shared, with Settings split into `resources/js/personal-workspace/settings/*`. Capabilities are presentation flags; backend ownership is authoritative.

Main Workspace is chat + Task / Reminder / Notification centers + Voice + compact **Настройки**. Memory and Integrations are **not** on the main screen; they live in Settings.

Workspace conversation delete is implemented for Owner and ordinary users. Sidebar overflow menu → confirmation dialog → `DELETE /jarvis/chats/{conversation}` or `DELETE /chat/chats/{conversation}`. Own personal conversations only (`ensureOwned`; Owner is not a bypass for someone else’s chat). Group conversations are 404. Hard delete of the chat and child messages/ephemeral screenshots; tasks, reminders, projects, persistent Storage files, and durable memories survive with sources detached. Deleting the open chat switches to the latest remaining personal chat, or creates `Основной` if none remain. No full page reload.

Phase C.1 Conversation Intelligence is **IMPLEMENTED / NOT VALIDATED**. Same Conversation Engine. Derived working context (topic mode, recent entities, trusted recent tool refs, temporary style) plus clarification/initiative policy. Mutation tools do not guess ids. Web composer can send a new message while a previous turn is thinking; stale JSON is ignored. Server generation is not cancelled.

Phase C.2 Beta (ElevenLabs realtime Web voice) is **IMPLEMENTED / NOT VALIDATED**. Parallel to Рация. Telegram Voice unchanged. Legacy removal NOT NOW.

Workspace Settings sections: Profile, Assistant, Memory, Productivity, Voice, Integrations. Desktop: nav + detail. Mobile: list → detail. Direct section: `?settings=memory` / `?settings=integrations` on first load (allowlist only). Opening Settings from the UI does not rewrite `history.state`, so the chat list stays intact.

After a successful foreground chat turn, badges and open panels refresh via `GET /jarvis/workspace/status` and `GET /chat/workspace/status` plus turn-payload counts. No page reload, no polling, no WebSocket. Scheduler events still appear on next open / Push / navigation.

Regular user capabilities: chat, memory, telegram_dm, reminders, tasks, notifications, cabinet, personal_workspace, profile, web_research, voice, storage. **Not** projects, admin, Google, GitHub. User Settings → Integrations shows Telegram pairing only.

---

## 6. Voice

Committed path: two Web modes. **Рация** (default): push-to-talk, Gemini STT, ElevenLabs HTTP TTS, responsive Orb — Owner MANUAL PASS for the core pipeline. **Диалог Beta**: ElevenLabs realtime transport + Jarvis Custom LLM adapter — IMPLEMENTED / NOT VALIDATED; disabled unless env is configured. Admin Voice panel shows Realtime Conversation Configured / Not configured. Each user chooses one of six curated voices in Workspace settings (`users.voice_id`); Beta passes it as an Agent TTS override when the catalog matches. Empty Gemini `audioTranscriptionConfig` is sent as JSON `{}`. If a selected ElevenLabs voice is unavailable on the account, TTS makes at most one fallback request to the instance/default voice; auth, quota, rate-limit, and generic server errors do not retry. Live Gemini/ElevenLabs validation was not run. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

Telegram Voice Replies (`sendVoice`): **MANUAL PASS**.  
Telegram Voice Input (DM `Message.voice` → existing Gemini STT → Core): **IMPLEMENTED / NOT VALIDATED**. Groups still store `[voice]` placeholder (no STT). Default Telegram reply mode remains **text**. C.2 does **not** instantiate a realtime ElevenLabs agent on Telegram. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

---

## 7. Personalization (M25U.3)

Table `user_assistant_profiles`. Tools: `get_assistant_profile`, `update_assistant_profile`, `complete_assistant_onboarding`. Owner seeded Jarvis / completed. User onboarding UI exists; Owner confirmed **entry**. Completion E2E not confirmed.

Personal voice preference: nullable `users.voice_id`; effective fallback is the configured instance/default voice. The same selected voice is passed explicitly to Web Voice and Telegram TTS.

---

## 8. Reminders

Phase B.1 Reminders 2.0: Owner **MANUAL PASS for confirmed live core flow** (Web Push, Reminder Center, basic user flow). Not exhaustive edge-case MANUAL PASS. Telegram remains an optional adapter.

## 8.1 Tasks & productivity

Phase B.2 **IMPLEMENTED / NOT VALIDATED**. Separate `tasks` domain, Task Center, Notification Center, opt-in Daily/Evening/Weekly briefs, bounded proactive suggestions. [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

---

## 9. Integrations

Code: Google OAuth (Gmail + Calendar tools; **no Drive**), GitHub OAuth + tools, Telegram bot, ElevenLabs TTS, Web Research (`gemini_google` / `tavily` / disabled). Owner-only except Voice/research/storage capabilities for users as listed above. Live Google/GitHub campaign: NOT VALIDATED.

---

## 10. What is not here

- Desktop / Tauri / tray / hotkey
- Mobile app
- Public registration
- Knowledge Graph
- Wake word
- Real-time WebSocket/SSE for scheduler events
- Telegram Voice Input live Owner checklist (code shipped)
- Phase C.1 live Owner checklist (code shipped; not MANUAL PASS)
- Phase C.2 Beta live Owner A/B (code shipped; not MANUAL PASS; do not remove Рация)
- Historical async retry/prune (classified; Owner decides)

Live campaigns: [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md). Core Reliability is IMPLEMENTED; historical failures CLASSIFIED.
