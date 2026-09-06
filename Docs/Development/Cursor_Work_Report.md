# Core Product Validation Finalization

## Starting HEAD

`bcca7ca8ea72181cb6414b2f9d3102178b8b6c63`, equal to `origin/main` at the start of this documentation close-out.

Branch `main`. No production code changes in this close-out. No dependency changes. No migrations. No tests. No builds.

## Validation campaign

Owner completed Core Daily Workflow by hand on a clean repeat chat **Validation Core 2**.

Cursor did not execute the scenarios, did not create production records, and did not run `phpunit` / `php artisan test` / Pest / live provider calls.

Runbook: [VALIDATION_CORE_WORKFLOW.md](../VALIDATION_CORE_WORKFLOW.md).

## 10 manual PASS scenarios

**CORE DAILY WORKFLOW: MANUAL PASS 10/10** (2026-09-07).

| # | Scenario | Result |
| --- | --- | --- |
| 1 | Conversation continuity | MANUAL PASS |
| 2 | Task + Reminder | MANUAL PASS |
| 3 | Internal watcher | MANUAL PASS |
| 4 | Knowledge | MANUAL PASS |
| 5 | Synthesis | MANUAL PASS |
| 6 | Waiting / commitments | MANUAL PASS |
| 7 | State change | MANUAL PASS |
| 8 | Overview | MANUAL PASS (after revalidation; see below) |
| 9 | Memory vs Knowledge | MANUAL PASS |
| 10 | Chat delete | MANUAL PASS |

## Confirmed product behavior

Only these behaviors are confirmed:

1. Conversation continuity / reference resolution
2. Task + visible/manageable subtask
3. Reminder create/update
4. Internal watcher creation
5. Knowledge cross-chat retrieval
6. Cross-source synthesis
7. Waiting / explicit commitments
8. State-change propagation through Task → Reminder → Watcher → Synthesis
9. Overview refresh / dedupe / canonical-state precedence
10. Humanized Workspace presentation
11. Memory vs Knowledge separation
12. Conversation delete preserving durable Tasks / Knowledge / Memory semantics

Layer wording (tested flows only, not all edge cases):

- **C.1:** MANUAL PASS for tested continuation / reference / clarification. Not full Conversation Intelligence coverage.
- **E.1 Knowledge:** MANUAL PASS for the tested core flow. Not all edge cases.
- **E.2 Watchers:** MANUAL PASS for the internal task watcher flow. External integrations remain deferred.
- **E.3 Cross-source Synthesis:** MANUAL PASS for the tested core synthesis / Overview / waiting / state-change flow.
- **Workspace Presentation:** MANUAL PASS after live revalidation of Scenario 8.

## Bugs found during campaign

The first Scenario 8 (earlier `Validation Core` chat, not Validation Core 2) was a **LIVE FAIL**.

After Scenario 7 closed the task chain, chat synthesis was correct, but **Обзор** still showed stale derived state (open work, blockers, waiting watchers, duplicated change cards) and backend wording on screen (enum names, task ids, adapter internals).

That is a stale derived-state regression plus presentation problems. It is **not** a claim that every Overview edge case outside that campaign was found.

## Fix commit

`bcca7ca8ea72181cb6414b2f9d3102178b8b6c63`

Canonical-state precedence for derived Overview slices, one fingerprint per semantic change, watcher re-evaluation when the task lives in `source_config`, and the Workspace presentation layer. Already on `origin/main` before this documentation close-out.

This close-out does not change that commit.

## Revalidation result

Owner repeated the chain on a clean chat **Validation Core 2**. Scenario 8 is **MANUAL PASS**. Scenarios 1–10 are **MANUAL PASS**. Workspace Presentation is **MANUAL PASS** after that live revalidation.

## Coverage boundaries

This PASS is the tested core daily chain. It does **not** mean every edge case of C.1, E.1, E.2, E.3, Tasks, Reminders, Overview, or Workspace Presentation is validated.

Briefs, proactive suggestions, and Notification Center as a full product are not claimed.

## Still deferred

- ElevenLabs realtime Диалог Beta C.2
- external watcher campaigns
- Gmail live validation
- Google Calendar live validation
- GitHub live validation (no separate confirmed manual campaign)
- Telegram Groups
- external watcher proposed action → confirmation → external write
- DST/timezone edge cases
- destructive storage edge cases
- historical retry/prune campaign
- full IDOR/security campaign
- Mobile / Client API
- optional future integrations

## Documentation updated

- [CURRENT_STATE.md](../CURRENT_STATE.md)
- [VALIDATION_CORE_WORKFLOW.md](../VALIDATION_CORE_WORKFLOW.md)
- [DEFERRED_VALIDATION.md](../DEFERRED_VALIDATION.md)
- [JARVIS_USER_OVERVIEW.md](../JARVIS_USER_OVERVIEW.md)
- this report

## Production safety

Documentation only. Production code was not changed in this close-out. No tests or builds were run because there are no code changes in this commit.
