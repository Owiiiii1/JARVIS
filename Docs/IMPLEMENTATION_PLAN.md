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
| Current Web Voice capture | Push-to-talk «Рация» only; supersedes hands-free capture | IMPLEMENTED |
| Per-user TTS voice | Six curated voices shared across Web/Telegram per user | IMPLEMENTED |
| M25U.1 | Shared `/chat` Personal Workspace | MANUAL PASS (core user workflow) |
| M25U.2 | User administration / isolation | MANUAL PASS (core user workflow) |
| M25U.3 | Assistant profiles, onboarding UI, reminders panel code | MANUAL PARTIAL (onboarding entry); reminders core later MANUAL PASS |
| Telegram Voice Replies | DM `sendVoice` via existing TTS; default text | MANUAL PASS |
| Telegram Voice Input | DM voice note → existing Gemini STT → Core | IMPLEMENTED / NOT VALIDATED |
| Workspace UX cleanup / conversation delete | Own personal chat delete; independent entities survive | MANUAL PASS |
| Phase C.1 | Conversation Intelligence | IMPLEMENTED / validation deferred |
| Phase C.2 Beta | ElevenLabs realtime Web voice; Рация kept | IMPLEMENTED / validation deferred |
| Core Reliability | Async jobs, classification, retry/recover commands | IMPLEMENTED; historical failures CLASSIFIED |

Historical detailed “implement this” write-ups for M0–M24 are obsolete as instructions. Git history remains the archive.

---

## B. Current gaps

| Gap | Reality |
| --- | --- |
| Reminder create without Telegram | MANUAL PASS (confirmed live core flow) |
| Reminder delivery | Telegram optional; no-channel stays Core due (`delivery_state=no_channel`, 30-minute recheck) |
| Recurrence | IMPLEMENTED; exhaustive DST/edge MANUAL PASS deferred |
| Reminders panel | Header **Напоминания**; MANUAL PASS for confirmed live core flow |
| Onboarding E2E | Entry confirmed; completion/profile update not Owner-confirmed |
| Google / GitHub live smoke | Code present; not Owner-validated as a campaign |
| A/B isolation campaign | Prepared, not executed |
| Web Push / Tasks / Daily Brief | B.1 Web Push MANUAL PASS (live core). B.2 Tasks/Notification Center/briefs/proactive IMPLEMENTED / NOT VALIDATED |
| Versioned Client API | Not implemented; **not** current work |
| Telegram Voice Input (STT) | IMPLEMENTED / NOT VALIDATED |
| Desktop | CANCELLED |
| Deferred live campaigns | See [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md); not blocking |

---

## C. Next executable milestones

### M25U.3.1 — Web Reminders without Telegram

**Status.** IMPLEMENTED / NOT VALIDATED. Do not treat as MANUAL PASS until Owner live test.

**Done in code**

- Panel visible via header **Напоминания** on `/jarvis` and `/chat` (capability `reminders`, not Telegram)
- User can create a reminder **without** Telegram
- Reminder persists in Core (`reminders` row, `user_id`)
- Panel lists own reminders; own reminder can be cancelled
- Telegram is an **optional** delivery adapter
- No-channel due reminders stay scheduled Core reminders
- No Web Push in this milestone

**Not in this milestone:** recurrence, snooze/edit/done, Tasks, browser notifications.

**Depends on:** current Reminder Engine + workspace panel code.

---

### After M25U.3.1 (still Phase A / start of B)

Live campaigns are deferred, not current work. See [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md).

### Phase B.1 — Reminders 2.0

**Status.** Owner **MANUAL PASS for confirmed live core flow**. Not exhaustive DST/recurrence/multi-device MANUAL PASS.

**In code and live core:** Web Push, Reminder Center v2, edit / snooze / done / cancel, recurrence, per-channel `reminder_deliveries`.

### Phase B.2 — Tasks & Proactive

**Status.** IMPLEMENTED / NOT VALIDATED. Owner-confirmed basic reminder flow is B.1, not B.2. Do not treat Tasks as MANUAL PASS until Owner live test.

**In code:** Tasks domain, Task Center, Notification Center, Daily/Evening/Weekly briefs (opt-in), bounded proactive engine, task↔reminder/conversation/project/calendar-reference.

**Not in this milestone:** mobile, knowledge graph, contacts, watchers, unrestricted autonomy.

Detail: [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

### Telegram Voice Replies / Input

**Replies.** MANUAL PASS. DM `sendVoice` via existing TTS; default text.

**Input.** IMPLEMENTED / NOT VALIDATED. Unchanged by C.2. Groups unchanged.

Detail: [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

### Phase C.1 — Conversation Intelligence

**Status.** IMPLEMENTED / validation deferred. Not MANUAL PASS.

**In code:** derived working context, topic continuity, reference resolver, clarification policy, trusted recent tool refs, unified personality presentation, bounded initiative, Web stale-response suppression. No new chat/memory schema.

Detail: [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md).

### Phase C.2 Beta — ElevenLabs realtime

**Status.** IMPLEMENTED / validation deferred. Not MANUAL PASS. **Рация remains** the default Web Voice path. Telegram Voice unchanged. Desktop CANCELLED.

### Core Reliability

**Status.** IMPLEMENTED. Historical async failures CLASSIFIED. Not a live validation milestone.

Detail: [Docs/Development/Cursor_Work_Report.md](Development/Cursor_Work_Report.md), [DEFERRED_VALIDATION.md](DEFERRED_VALIDATION.md).

---

## D. Deferred strategic milestones

| Item | Phase |
| --- | --- |
| Web Push / Notification Center | B.1 transport MANUAL PASS / B.2 center IMPLEMENTED / NOT VALIDATED |
| Recurrence, snooze, done, edit | B.1 MANUAL PASS for confirmed live core; DST edges deferred |
| Tasks domain + relations | B.2 IMPLEMENTED / NOT VALIDATED |
| Daily Brief / Weekly Review | B.2 IMPLEMENTED / NOT VALIDATED |
| C.2 further streaming (Core tokens before persist) | later; Beta already IMPLEMENTED / validation deferred |
| Telegram Voice Replies (`sendVoice`) | MANUAL PASS |
| Telegram Voice Input (STT) | IMPLEMENTED / NOT VALIDATED |
| Wake word | research only, not mandatory |
| Mobile companion | D |
| Versioned Client API | if/when Mobile (or similar) starts |
| Knowledge Graph / People / watchers / automations | E |
| Desktop / Tauri / tray / hotkey | CANCELLED |
