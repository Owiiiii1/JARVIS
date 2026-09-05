# Implementation plan

Executable plan. Runtime facts: [CURRENT_STATE.md](CURRENT_STATE.md). Direction: [ROADMAP.md](ROADMAP.md).

Roles of docs:

| File | Role |
| --- | --- |
| ROADMAP.md | Product direction |
| IMPLEMENTATION_PLAN.md | Next milestones + concise completed history |
| CURRENT_STATE.md | Actual implementation snapshot |
| DECISIONS.md | Durable ADRs |
| DEVELOPMENT_PHASES.md | Historical four-phase archive |

Do not start a listed “completed” milestone again. Do not treat Desktop as upcoming work.

---

## A. Completed (concise)

| ID | Result | Validation |
| --- | --- | --- |
| M0 | CRM cleanup, host baseline | COMPLETED |
| M1 | Owner/user identity, access_code `2000` | COMPLETED |
| M2 | Telegram pairing | COMPLETED |
| M3–M6 | Conversations, Chat Selector, Conversation Engine | COMPLETED |
| M4 | Per-user General Prompt | COMPLETED |
| M7–M8 / M25U.2 | User administration, impersonation, isolation | MANUAL PASS (core login/`/chat`) |
| M9–M10 | Reminder engine (Telegram create/delivery) | IMPLEMENTED |
| M11–M14 | Memory Engine | IMPLEMENTED |
| M15–M16 | Telegram Groups + analysis tools | IMPLEMENTED / NOT VALIDATED |
| M17 | Projects (Owner) | IMPLEMENTED |
| M18–M19 | Google Calendar + Gmail | IMPLEMENTED / NOT VALIDATED |
| M21 | GitHub OAuth + tools | IMPLEMENTED / NOT VALIDATED |
| M22 | Owner `/jarvis` workspace | MANUAL PASS (images/storage/research slices) |
| M22.1–M22.3 | Attachments, Storage, Web Research, Context Budget | MANUAL PASS (selected Owner scenarios) |
| M23 | Voice runtime sessions | MANUAL PASS (as part of E2E Voice) |
| M23.2 | Gemini STT | MANUAL PASS |
| M24 | Voice Orb UI | MANUAL PASS |
| M24.1 | Hands-free local VAD | MANUAL PASS |
| M24.1.1 | VAD silence hotfix | MANUAL PASS |
| M25U.1 | Shared `/chat` Personal Workspace | MANUAL PASS (core user workflow) |
| M25U.2 | User administration / isolation | MANUAL PASS (core user workflow) |
| M25U.3 | Assistant profiles, onboarding UI, reminders panel code | MANUAL PARTIAL (onboarding entry); panel LIVE BUG |

Historical detailed “implement this” write-ups for M0–M24 are obsolete as instructions. Git history remains the archive.

---

## B. Current gaps

| Gap | Reality |
| --- | --- |
| Reminder create without Telegram | Code throws `telegram_not_connected` (`ReminderService::assertCanCreate`) |
| Reminder delivery | Telegram only |
| Recurrence | Column exists; create tool rejects recurrence |
| Reminders panel | Code + routes exist; Owner cannot see panel in live user workspace |
| Onboarding E2E | Entry confirmed; completion/profile update not Owner-confirmed |
| Google / GitHub live smoke | Code present; not Owner-validated as a campaign |
| A/B isolation campaign | Prepared, not executed |
| Web Push / Tasks / Daily Brief | Not implemented |
| Versioned Client API | Not implemented; **not** current work |
| Telegram Voice Replies / Telegram Voice Input | NOT IMPLEMENTED |
| Desktop | CANCELLED |

---

## C. Next executable milestones

### M25U.3.1 — Web Reminders without Telegram

**Status.** PLANNED. Do not implement in M26D.

**Goals**

- Fix reminder panel visibility on `/jarvis` and `/chat`
- User can create a reminder **without** Telegram
- Reminder persists in Core (`reminders` row, `user_id`)
- Panel lists own reminders
- Own reminder can be cancelled / managed
- Telegram becomes an **optional** delivery adapter
- No Web Push in this milestone

**Not in this milestone:** recurrence, snooze/edit/done, Tasks, browser notifications.

**Depends on:** current Reminder Engine + workspace panel code.

---

### After M25U.3.1 (still Phase A / start of B)

- Full onboarding manual validation
- Selected Google / GitHub manual validation if Owner wants
- Optional A/B isolation campaign

### Telegram Voice Replies

**Status.** PLANNED / DEFERRED. Small independent channel enhancement. **Not** the next executable milestone (that remains M25U.3.1). **Not** a new major phase.

Place after reminder hardening unless Owner reorders.

**Scope (when started)**

- Audit Telegram adapter (DM text-only inbound/outbound today)
- Audit inbound Telegram voice (currently **not** transcribed)
- Reuse `TextToSpeechManager` / `TextToSpeechProvider`
- Optional audio conversion (likely OGG/OPUS for `sendVoice`; ffmpeg only if audit requires it)
- `sendVoice` delivery of TTS over canonical assistant text
- Text fallback on TTS/conversion/Telegram failure
- Per-user `text` / `voice` / `auto` preference
- Temporary audio lifecycle (no default archive)
- Safe error handling
- Manual production validation

Detail: [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

---

## D. Deferred strategic milestones

| Item | Phase |
| --- | --- |
| Web Push / Notification Center | B |
| Recurrence, snooze, done, edit | B |
| Tasks domain + relations | B |
| Daily Brief / Weekly Review | B |
| Streaming STT/TTS, richer barge-in | C |
| Telegram Voice Replies (`sendVoice`) | C (small; after M25U.3.1) |
| Wake word | research only, not mandatory |
| Mobile companion | D |
| Versioned Client API | if/when Mobile (or similar) starts |
| Knowledge Graph / People / watchers / automations | E |
| Desktop / Tauri / tray / hotkey | CANCELLED |
