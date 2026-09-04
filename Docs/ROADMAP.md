# Дорожная карта

Product direction as of **M26D** (2026-09-05). Executable next work: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Actual runtime: [CURRENT_STATE.md](CURRENT_STATE.md).

The old four-phase model (Telegram MVP → Memory → Workspace+Desktop+Voice → conversational intelligence) is **HISTORICAL**. It no longer describes what to build next.

**Primary interactive client:** Web Personal Workspace (`/jarvis` Owner, `/chat` users).  
**Desktop client:** **CANCELLED**.  
**Mobile:** optional future companion, **not** current priority.  
**Voice:** a Web modality. Basic hands-free flow is **MANUAL PASS**.

---

## PHASE A — Core Personal Jarvis

**Status.** Mostly complete. Web is the product.

Includes (in code unless noted):

- Identity / owner vs user / no public registration
- Telegram DM pairing + Chat Selector
- Persistent conversations / Conversation Engine
- Shared Personal Workspace
- Admin user management + impersonation
- AI provider abstraction (Owner Conv / Owner Analysis / Default User Conv)
- Personal Memory Engine + Context Budget
- Persistent Storage + ephemeral attachments
- Web Research
- Projects (Owner-only)
- Telegram Groups + group knowledge
- Google Calendar / Gmail tools (Owner)
- GitHub tools (Owner)
- Assistant personalization / onboarding foundation
- Voice (STT Gemini, TTS ElevenLabs, local VAD, Orb)
- Reminder engine foundation (Telegram-gated create/delivery)

### Validation (Owner-confirmed)

| Area | Status |
| --- | --- |
| Ordinary user create / login / `/chat` / basic requests | MANUAL PASS |
| Owner Workspace images, Storage-through-chat, Gemini Google Search | MANUAL PASS |
| Voice start, mic, hands-free end-of-turn, Gemini STT, reply, ElevenLabs TTS, VAD hotfix | MANUAL PASS |
| Onboarding «Знакомство» entry | MANUAL PARTIAL |
| Full onboarding completion / profile update E2E | IMPLEMENTED / NOT VALIDATED |
| Reminders panel in live user workspace | IMPLEMENTED IN CODE / LIVE BUG |
| Reminder create without Telegram | NOT SUPPORTED (known gap) |
| Combined Google / GitHub live smoke | IMPLEMENTED / NOT VALIDATED |
| A/B isolation campaign | PREPARED / NOT EXECUTED |

### Phase A remaining

1. **M25U.3.1 Web Reminders without Telegram** — next executable milestone
2. Reminders panel visibility bug
3. Full onboarding manual validation
4. Selected integration manual validation (Google / GitHub)
5. Optional A/B isolation campaign

---

## PHASE B — Time & Productivity

**Status.** PLANNED. After reminders-without-Telegram.

Jarvis should become time-aware and action-aware, not merely chat-aware.

1. Reminder Core decoupled from Telegram (target; see [REMINDERS.md](REMINDERS.md))
2. Web Reminder Center
3. Web Push / browser notifications
4. Recurring reminders
5. Snooze / done / edit
6. Tasks (separate domain from reminders)
7. Task ↔ Reminder relationships
8. Task ↔ Conversation relationships
9. Notification Center
10. Calendar ↔ Tasks ↔ Reminders
11. Daily Brief
12. Evening / Weekly Review
13. Controlled proactive suggestions

Detail: [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

---

## PHASE C — Natural Conversation

**Status.** Basic Voice is **complete** (MANUAL PASS). This phase is **future improvement**, not a redo of VAD / hands-free.

Do **not** plan as future:

- basic local VAD
- hands-free end-of-turn
- barge-in foundation
- Gemini STT / ElevenLabs TTS path
- Voice as a Web Workspace mode

Future:

- lower latency
- streaming STT if valuable
- streaming TTS if valuable
- more robust barge-in / conversational overlap
- better short-pause policy
- text generation cancellation
- incomplete phrases / pronouns
- topic continuity
- clarification policy
- stable personality
- better working memory
- natural conversational initiative

**Wake word:** not mandatory. Desktop is cancelled; a wake word in a normal browser has limited product value. Optional future research (mobile/native or always-open environments only).

[HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md), [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md).

---

## PHASE D — Mobile companion

**Status.** DEFERRED. Not current priority. Not a new Core.

Potential value: reliable push, voice on the go, camera / photo, share-to-Jarvis, location if permitted, quick capture, mobile notifications.

Web remains the primary product. No Desktop dependency. [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md).

Versioned Client API is built **only if** Mobile (or another first-party non-Web client) actually starts. [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md).

---

## PHASE E — Knowledge & Proactive Jarvis

**Status.** PLANNED strategic layer. Not committed immediate implementation.

- Personal Knowledge Graph (optional structured layer over Memory / raw sources — does **not** replace Memory Engine)
- People / Contacts intelligence
- Richer Project intelligence
- Timeline / activity understanding
- Cross-source entity relationships
- Event-triggered workflows / watchers
- Controlled automations
- Proactive assistant (event/condition driven, not unsolicited chatter)
- Daily / Weekly synthesis
- Conditional alerts

Examples: “When Apple replies, read the mail and say what to do.” “When a GitHub commit lands on this project, review it.” “If the deadline is tomorrow and the task is open — remind me.”

Strict permissions, confirmation, and audit required.

---

## Cancelled

| Item | Decision |
| --- | --- |
| Desktop client / Tauri / JARVIS-Desktop | CANCELLED (ADR-235) |
| System tray / global hotkey / desktop native shell | CANCELLED |
| Desktop-specific auth / API lifecycle | CANCELLED |
| Desktop as prerequisite for Voice / clients / API | CANCELLED |
