# Watchers and event-driven automations

**Status.** Phase E.2 **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS. Phase E.3 synthesis consumes watcher occurrences as indexed evidence ([CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md)). Phase E as a whole is **not** complete.

Watchers are explicit, bounded, user-scoped conditions: “when X happens, notify / remind / propose Y.” They are **not** an unrestricted autonomous agent, not B.2 proactive heuristics, and not Reminders.

| Object | Question |
| --- | --- |
| Reminder | Notify at a **known time** |
| Watcher | Notify when a **future condition/event** is true |
| Task | A **work item** |
| B.2 Proactive | Bounded **heuristic** suggestion over tasks/time (not a persisted user condition) |
| Knowledge Event | An **observed fact** on the timeline |
| Memory | A **durable remembered fact** |

Detail: [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md), [KNOWLEDGE_LAYER.md](KNOWLEDGE_LAYER.md), [REMINDERS.md](REMINDERS.md), [NOTIFICATIONS.md](NOTIFICATIONS.md).

---

## Principles

Explicit. Bounded. Source-grounded. Deterministic evaluators. Idempotent. Auditable. User-scoped. Rate-limited. Confirmation-safe. Provider-neutral. Channel-independent.

No generated PHP/SQL/HTTP. No `eval()`. No generic public webhook receiver. No silent Gmail/Calendar/GitHub writes.

---

## Domain

Tables: `watchers`, `watcher_occurrences` (unique `watcher_id` + `trigger_fingerprint`).

Statuses: `active`, `paused`, `completed`, `failed`, `cancelled`.

Health: `healthy`, `waiting`, `blocked`, `paused`, `failed`.

Mode: `one_shot` (completes after a successful occurrence) or `recurring`.

Trigger types (closed set): `knowledge_event`, `task_state`, `reminder_state`, `time_condition`, `calendar_event`, `gmail_message`, `github_event`.

Condition types (closed set): `event_exists`, `entity_event_type`, `status_equals`, `status_changed`, `deadline_within`, `overdue_by`, `new_item`, `sender_matches`, `subject_contains`, `thread_received_reply`, `calendar_changed`, `github_new_commit`, `github_pr_state_changed`, `github_workflow_failed`.

Reactions (closed set): `notify`, `create_notification`, `create_reminder`, `create_task`, `run_internal_analysis`, `propose_action`.

External writes are **never** executed by a watcher. `propose_action` creates a Notification Center item with `pending_action` metadata for later foreground confirmation.

---

## Evaluation pipeline

1. User creates a watcher (chat tool or Workspace Center).
2. First check **establishes a baseline/cursor**. Historical Gmail/GitHub/Calendar/knowledge items do not fire.
3. Later observations are normalized by a source adapter (`WatcherSourceAdapter`).
4. `WatcherConditionEvaluator` is deterministic.
5. Unique fingerprint + cooldown + per-watcher/day + global/day caps suppress spam. Short bursts may aggregate.
6. `WatcherReactionExecutor` runs once per occurrence.
7. External observations that matter may be ingested as Knowledge events (`WatcherKnowledgeBridge`) with a distinct fingerprint. `watcher_triggered` knowledge events do not re-enter the dispatcher.

Internal events (`KnowledgeEventCreated`, task/reminder changes) dispatch `EvaluateWatcherJob` immediately. Time and integration watchers also run from `jarvis:watchers:dispatch` every 5 minutes (`withoutOverlapping`). Jobs use the existing `default` queue.

---

## External polling

Polling happens **only** for active watchers that need it, with bounded queries.

Defaults (config `watchers.cadence`): Gmail/GitHub ~8 minutes, Calendar/internal ~5 minutes. Auth failures block the watcher and notify once (`Watcher needs reconnect`). Transient errors back off. No global inbox/repo/calendar mirror.

---

## Ownership, privacy, retention

Every ref is ownership-checked at create and at execution. Config and occurrences store filters, stable ids, and bounded summaries — not tokens, full bodies, or raw payloads.

Occurrence prune: `jarvis:watchers:prune` (dry-run by default, 90 days). Do not run a live destructive prune as part of this milestone.

Limits (config): 25 active external / 100 internal per user; daily trigger and notification caps.

---

## Workspace

Center **Автоматизации** on the main Workspace chrome (`/jarvis/watchers`, `/chat/watchers`), same pattern as Tasks/Reminders. List, simple create, pause/resume/cancel, recent occurrences. Chat remains the primary authoring path. Foreground tool writes refresh via existing workspace status (no extra polling). Scheduler-triggered hits surface through Notification Center / Web Push.

---

## AI tools

`create_watcher`, `list_watchers`, `get_watcher`, `update_watcher`, `pause_watcher`, `resume_watcher`, `cancel_watcher`, `list_watcher_occurrences`, `run_watcher_now`.

Capability `watchers` (regular users: internal sources; Gmail/Calendar/GitHub remain Owner). Core writes (`provider` null). Changing source/condition resets the baseline so history is not replayed. `run_watcher_now` is a check only.

Tool prompt: Reminder = known time; Watcher = future condition; Task = work item; B.2 proactive is separate.

---

## Not in E.2

Generic agent loop. Public webhooks. Zapier UI. Automatic external writes. Live provider validation. Production prune. Marking all of Phase E complete.

Synthesis may **suggest** a watcher (“Ты регулярно ждёшь ответы Apple”) but must not auto-create one. Watcher occurrences feed recent-changes / waiting-for; the same real-world event is deduped with Knowledge.
