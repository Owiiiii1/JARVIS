# Jarvis — current implementation snapshot

**Date:** 2026-09-05 (M26D documentation realignment)  
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

**PASS — Voice (M23–M24.1.1):**

- Voice mode starts
- microphone / listening
- hands-free turn ends after pause
- Gemini STT
- Jarvis generates a reply
- ElevenLabs TTS plays audio
- post-VAD hotfix works

Voice stage is **CLOSED**.

**PARTIAL — M25U.3:**

- Onboarding / «Знакомство» **appears** (Owner)
- Full onboarding conversation / completion / profile update: **not** MANUAL PASS
- Reminders panel: **not visible** in the real user workspace (LIVE BUG)
- `create_reminder` without Telegram: **refuses** (known gap, still in code)

**Not claimed:** A/B IDOR campaign; combined Google/GitHub live campaign; Tavily; `fetch_web_page` as a distinct Owner check; screenshot purge; destructive Storage delete.

---

## 1. Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| HEAD (origin/main at M26D) | `ef6ed9cc7121ee7a9b07f2d4c6a8e9cc16c9e9e2` |
| Message | `fix: improve voice activity silence detection` |
| Previous | `de7d579` M25U.3; `7d9c83f` M24.1; `72b4e3c` M25U.2; `00b54e0` Gemini STT |
| Origin | `https://github.com/Owiiiii1/JARVIS.git` |

This documentation commit does **not** include uncommitted Voice client experiments that may exist in the working tree.

`.env` is gitignored.

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
| Scheduler | crontab `schedule:run`; `jarvis:reminders:dispatch` every minute; attachment purge hourly; `jarvis:voice:cleanup-temp` every 5 minutes; `queue:work` for `memory,default` |
| Telegram queue | deploy-user crontab `flock` worker (host-specific) |

Vite production build is generated on deploy (`public/build` gitignored).

---

## 4. Database

Engine: MySQL. **47 tables** (including `migrations`). CRM tables were dropped (M0). **33** app migrations recorded; all listed as Ran.

### Tables (product)

`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `ai_provider_settings`, `ai_role_settings`, `user_ai_settings`, `telegram_bot_settings`, `channel_identities`, `conversations`, `messages`, `message_attachments`, `reminders`, `conversation_summaries`, `topics`, `message_topic_relations`, `memories`, `memory_sources`, `memory_revisions`, `user_profiles`, `memory_analysis_runs`, `projects`, `project_conversations`, `project_topics`, `project_memories`, `project_groups`, `telegram_groups`, `telegram_group_participants`, `telegram_group_analysis_runs`, `telegram_group_knowledge`, `telegram_group_knowledge_sources`, `telegram_group_knowledge_revisions`, `integration_accounts`, `tool_execution_logs`, `tool_confirmations`, `stored_files`, `stored_file_chunks`, `message_stored_files`, `web_research_settings`, `voice_sessions`, `voice_settings`, `user_assistant_profiles`.

Row counts at audit (order of magnitude, not a metric): users 2 (1 owner + 1 user), conversations 9, messages 145, reminders 13, assistant profiles 1, voice_sessions 11, stored_files 1.

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

Frontend: `resources/js/personal-workspace/PersonalWorkspace.jsx` shared. Capabilities are presentation flags; backend ownership is authoritative.

Regular user capabilities: chat, memory, telegram_dm, reminders, cabinet, personal_workspace, profile, web_research, voice, storage. **Not** projects, admin, Google, GitHub.

---

## 6. Voice

Committed path: hands-free VAD, Gemini STT, ElevenLabs TTS, Orb. Owner MANUAL PASS. Admin Voice settings under Integrations. [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## 7. Personalization (M25U.3)

Table `user_assistant_profiles`. Tools: `get_assistant_profile`, `update_assistant_profile`, `complete_assistant_onboarding`. Owner seeded Jarvis / completed. User onboarding UI exists; Owner confirmed **entry**. Completion E2E not confirmed.

---

## 8. Reminders

Core + scheduler + Telegram delivery. Create requires Telegram identity. Panel code present; live visibility **bug**. Target architecture: channel-independent reminders ([REMINDERS.md](REMINDERS.md)). Next: M25U.3.1.

---

## 9. Integrations

Code: Google OAuth (Gmail + Calendar tools; **no Drive**), GitHub OAuth + tools, Telegram bot, ElevenLabs TTS, Web Research (`gemini_google` / `tavily` / disabled). Owner-only except Voice/research/storage capabilities for users as listed above. Live Google/GitHub campaign: NOT VALIDATED.

---

## 10. What is not here

- Desktop / Tauri / tray / hotkey
- Mobile app
- Public registration
- Web Push
- Tasks domain
- Knowledge Graph
- Proactive engine
- Wake word
