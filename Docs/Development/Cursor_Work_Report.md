# Cursor work report — M26D Full Documentation Realignment

**Date:** 2026-09-05  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`  
**Milestone:** M26D — documentation only

---

## Actual starting HEAD

| Item | Value |
| --- | --- |
| Branch | `main` |
| origin/main HEAD at audit | `ef6ed9cc7121ee7a9b07f2d4c6a8e9cc16c9e9e2` |
| Message | `fix: improve voice activity silence detection` |
| Recent history | `de7d579` onboarding/reminders panel; `7d9c83f` hands-free VAD; `72b4e3c` user admin; `00b54e0` Gemini STT |

Uncommitted Voice client/TTS experiments in the working tree were **not** treated as shipped and were **not** committed.

---

## Scope

DOCUMENTATION-ONLY. No product code, migrations, frontend, services, config behavior, DB writes, or live AI/provider tests.

Allowed checks: git, routes, migrate:status, schema/counts, grep, read code.

---

## Docs audited

Index, architecture, roadmap, plan, current state, decisions, phases, project, users, API, database, channels, voice, reminders, personalization, clients, integrations, memory, conversation, storage, research, projects, groups, context budget.

Source of truth: committed code + Owner-confirmed manual results + Owner product decisions. Old docs used only as historical reference.

---

## Stale contradictions found

- Four-phase roadmap (Telegram MVP → Memory → Workspace+Desktop+Voice → Phase 4) treated as current plan
- Desktop / Tauri / JARVIS-Desktop / tray / hotkey as upcoming work
- Client API as a near-term Desktop/Mobile prerequisite
- Voice “IMPLEMENTED / NOT VALIDATED”, “hands-free future”, “pause TBD”
- Reminders Telegram-only as **target** architecture
- Cabinet as the product name for personal chat
- CURRENT_STATE leftover baseline (old user/schema/auth wording in prior file)
- USERS_AND_CABINET claimed Telegram “handlers нет / бот не отвечает”
- PROJECT.md still described Laravel as “операционная оболочка, не Jarvis Core”
- ADR-046/039 still the unspoken product target
- ADR-173/208 still implying Voice is unvalidated

---

## Desktop references

- **Deleted** `Docs/CLIENTS/DESKTOP_APP.md`
- Removed active links to that file
- Cancellation recorded as **ADR-235**
- Historical ADRs 006 / 088 / 090 / 091 / 093 / 170 / 178 / 179 marked SUPERSEDED or CANCELLED where they planned Desktop
- Remaining “Desktop” mentions are either CANCELLED labels, CSS `hover: hover` viewport notes (reworded to wide-pointer), leftover `voice_sessions.origin` enum values in code (documented, not changed), or superseded ADR text

---

## Deleted / archived files

| File | Action |
| --- | --- |
| `Docs/CLIENTS/DESKTOP_APP.md` | **deleted** |
| `Docs/DEVELOPMENT_PHASES.md` | retained as **HISTORICAL / SUPERSEDED** archive with pointer to Phases A–E |
| Older ADRs | retained; obsolete targets explicitly superseded |

---

## New roadmap phases

| Phase | Role |
| --- | --- |
| A | Core Personal Jarvis — mostly complete; remaining hardening listed |
| B | Time & Productivity (reminders without Telegram, Web Push, Tasks, briefs) |
| C | Natural Conversation — basic Voice already done |
| D | Mobile companion — optional, not current priority; no Desktop |
| E | Knowledge & Proactive Jarvis — KG, watchers, bounded proactivity |

Next executable: **M25U.3.1 Web Reminders without Telegram** (not implemented in this milestone).

---

## Actual Voice status

Owner MANUAL PASS. Stage **CLOSED**.

| ID | Status |
| --- | --- |
| M23 Voice Runtime | MANUAL PASS |
| M23.2 Gemini STT | MANUAL PASS (E2E) |
| M24 Voice UI / Orb | MANUAL PASS |
| M24.1 Hands-Free Voice | MANUAL PASS |
| M24.1.1 VAD Silence Hotfix | MANUAL PASS |

Committed flow: Voice selection → mic permission → listening → local VAD → end-of-turn → Gemini STT → ConversationTurnService → ElevenLabs TTS → playback → listening. Mic = mute/unmute. Same `conversation_id`. No second Voice brain.

---

## Actual User status

| Item | Status |
| --- | --- |
| Admin-created ordinary user, login, `/chat`, ordinary requests | MANUAL PASS |
| Isolation A/B campaign | IMPLEMENTED / NOT VALIDATED |
| Canonical URLs | Owner `/jarvis`, user `/chat`, `/cabinet` compatibility |

---

## M25U.3 live status

| Item | Status |
| --- | --- |
| Onboarding UI entry («Знакомство») | MANUAL PARTIAL / ENTRY CONFIRMED |
| Full onboarding completion / profile update E2E | not MANUAL PASS |
| Reminders panel | IMPLEMENTED IN CODE / LIVE BUG (not visible in real user workspace) |
| create_reminder without Telegram | NOT SUPPORTED (code still requires Telegram identity) |

---

## Reminders current vs target

**CURRENT:** Core table + scheduler exist; create gated on Telegram identity; delivery Telegram-only; recurrence rejected on create; panel code present but live visibility bug.

**TARGET:** Reminder is a Core object independent of delivery channel. Telegram is an optional adapter. Web Workspace / future Web Push / future Mobile Push. Not implemented.

---

## CURRENT_STATE rebuild

Rebuilt from schema (47 tables), migrations Ran, routes, capabilities, workspace paths, Voice, personalization, reminders, admin, deploy. Not a patch on the old baseline. Describes **committed** HEAD `ef6ed9c`, not local uncommitted Voice experiments.

---

## IMPLEMENTATION_PLAN restructure

A. Completed milestones (concise)  
B. Current gaps  
C. Next executable (M25U.3.1 first)  
D. Deferred strategic / CANCELLED Desktop  

Old “implement this” write-ups for M0–M24 are no longer the working instructions.

Doc roles: ROADMAP = direction; IMPLEMENTATION_PLAN = executable; CURRENT_STATE = snapshot; DECISIONS = ADRs; DEVELOPMENT_PHASES = historical.

---

## Docs intentionally retained as historical

- `Docs/DEVELOPMENT_PHASES.md` — archive banner
- Pre-M26D ADRs in `Docs/DECISIONS.md` — not deleted; superseded in place (046, 039, 091, 093, 170, 173, 208, …)
- Capability name `cabinet` in code — leftover identifier; docs explain Cabinet is legacy wording

---

## New / rewritten docs

- `Docs/README.md` — start-here index
- `Docs/ROADMAP.md` — Phases A–E
- `Docs/CURRENT_STATE.md` — rebuilt
- `Docs/IMPLEMENTATION_PLAN.md` — restructured
- `Docs/ARCHITECTURE.md`, `Docs/CHANNELS.md`, `Docs/API.md`, `Docs/DATABASE.md`
- `Docs/REMINDERS.md` — current vs target
- `Docs/TASKS_AND_PRODUCTIVITY.md` — Tasks, briefs, proactive, KG, watchers
- `Docs/CLIENTS/MOBILE_APP.md`, `Docs/CLIENTS/CLIENT_API.md`
- Voice / personalization / users / project / conversation / memory updates
- ADRs **235–245**

---

## Checks performed

- `git fetch origin`; origin/main HEAD `ef6ed9c` at audit
- `git log` recent milestones
- migrations / table list / `migrate:status` (all Ran)
- models / reminder create gate / reminder panel routes
- workspace `/jarvis` `/chat` `/cabinet`
- capabilities `UserCapability::forRegularUser()`
- no product files staged for this commit
- no live AI / STT / TTS / provider tests

---

## NO PRODUCT CODE CHANGES

This milestone changed Markdown under `Docs/` only (plus this report). No migrations, controllers, frontend, services, or config behavior.

---

## NO LIVE TESTS
