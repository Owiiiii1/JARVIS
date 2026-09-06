# Phase E.2 — Watchers & Event-driven Automation

## Starting HEAD

`ab12ab54f52f0991f9cc5ff80cc1bce798068828` (`feat: add personal knowledge layer`). Working tree was clean. `HEAD` == `origin/main`.

## Existing Knowledge/Productivity architecture

E.1 already persisted `knowledge_events` through `KnowledgeIngestionService`. B.2 Tasks / Reminders / Notification Center / Web Push / `jarvis:proactive:dispatch` were already the delivery and heuristic layers. Integrations (Gmail, Calendar, GitHub) were Owner tools with confirmation policy. E.2 adds **explicit persisted conditions** on top of that stack. It does not replace Memory, Knowledge, Tasks, Reminders, or B.2 proactive.

## Watcher domain

Additive tables `watchers` and `watcher_occurrences`. User-scoped. Statuses `active` / `paused` / `completed` / `failed` / `cancelled`. Health `healthy` / `waiting` / `blocked` / `paused` / `failed`. Mode `one_shot` or `recurring`. Capability `watchers` for regular users (internal sources); Gmail/Calendar/GitHub remain Owner.

## Trigger model

Closed set: `knowledge_event`, `task_state`, `reminder_state`, `time_condition`, `calendar_event`, `gmail_message`, `github_event`. No arbitrary trigger strings.

## Condition model

Closed evaluators in `WatcherConditionEvaluator` (event exists, status, deadline/overdue, sender/subject/thread, calendar changed, GitHub commit/PR/workflow). No `eval()`, no dynamic SQL, no model-invented executable predicates at runtime.

## Source adapters

`WatcherSourceAdapter` implementations: Knowledge, Task, Reminder, Time, Gmail, Calendar, GitHub. Live clients sit behind contracts; tests bind fakes. Adapters return bounded `WatcherObservation` DTOs.

## Internal event dispatch

`WatcherEvaluationDispatcher` runs on new Knowledge events (not `watcher_triggered`, not idempotent hits), Task mutations, and Reminder status changes. Dispatches `EvaluateWatcherJob` on the existing `default` queue.

## External polling

Only active watchers with Gmail/Calendar/GitHub sources are checked, using their stored bounded query. No global inbox/repo/calendar sync. Cadence is config-driven (about 5–8 minutes).

## Baseline / cursor semantics

The first evaluation stores seen ids/fingerprints and does **not** fire. Later identical fingerprints are skipped. Updating source/condition resets the cursor and takes a new baseline so history is not replayed. `run_watcher_now` is a check only.

## Occurrences / dedupe

Unique `(watcher_id, trigger_fingerprint)`. Cooldown, per-watcher daily cap, global daily notification cap, optional aggregation window. One-shot completes after a successful occurrence. Recurring stays active.

## Reactions

`WatcherReactionExecutor`: notify / notification inbox, create reminder, create task, bounded Analysis-AI brief, or `propose_action`. Idempotent on occurrence status.

## Confirmation safety

Watcher create/pause/resume/cancel are Core writes (`provider` null) when the user asked to watch. Reactions never send Gmail, write Calendar, or write GitHub. External intent becomes a Notification Center `pending_action` for later foreground confirmation.

## Gmail watcher

Thread/sender/subject/query required. Fake-tested: old messages baseline, new message fires once. Live client uses existing Gmail integration; not live-validated.

## Calendar watcher

Event id / calendar / query required. Detects new / etag change / cancelled via normalized observations. Fake-tested time/etag change.

## GitHub watcher

Repository required. New commit, PR state, workflow failure (conclusion `failure`). Fake-tested new commit. Polling only; no webhook receiver.

## Task / time watcher

Cheap internal checks. Deadline-within and overdue-by use task due timestamps. Scheduler handles time crossing; task mutations dispatch immediately.

## Knowledge watcher

Subscribes to entity/project/event-type allowlists. Local dispatch when E.1 records a **new** event. Optional semantic importance is not a default LLM classify-everything path.

## Notification delivery

Existing Notification Center + B.1 Web Push. Core occurrence is channel-independent. Telegram is not a watcher existence requirement.

## Anti-spam / cooldown

Per-watcher cooldown, fingerprint uniqueness, daily caps, aggregation, one reconnect notification for blocked integrations, quiet existing productivity settings untouched.

## Scheduler / queue

`jarvis:watchers:dispatch` every 5 minutes, `withoutOverlapping`. Claims `next_check_at` before dispatching the unique job. Queue: `default` (already consumed). `jarvis:watchers:prune` dry-run by default.

## Reliability

Auth → health `blocked`, long cadence, one reconnect notification. Transient → backoff without hammering. Classified via Core Reliability; report includes watcher status/health counts.

## Ownership / privacy

Refs checked at create and execution. No tokens in config/occurrences. Bounded summaries only. Test teardown deletes occurrences then watchers.

## Workspace UI

Main chrome **Автоматизации** (not Settings), mirrors `/jarvis/watchers` and `/chat/watchers`. List, simple create, pause/resume/cancel, recent triggers. Foreground chat refreshes via existing workspace status token. `?watchers=1` opens the panel.

## AI tools

`create_watcher`, `list_watchers`, `get_watcher`, `update_watcher`, `pause_watcher`, `resume_watcher`, `cancel_watcher`, `list_watcher_occurrences`, `run_watcher_now`. Prompt distinguishes Reminder vs Watcher vs Task vs B.2 proactive.

## Migrations

Additive `2026_09_06_161626_create_watchers_tables`. Applied. No destructive changes. No historical watcher backfill.

## Automated tests

`tests/Feature/WatchersTest.php` (isolated users, fakes only): creation, foreign refs, Gmail/GitHub/Calendar baseline vs new item, fingerprint uniqueness, one-shot vs recurring, cooldown, pause/cancel, task deadline, knowledge event dispatch + ingest dedupe, auth block without hammering, transient backoff, notifications, internal reaction idempotence, proposed external action, scheduler claim, prune dry-run, tool guidance, B.2 remains separate, no implicit watcher on task create. `WorkspaceUxCleanupTest` covers WatchersPanel `refreshToken`.

## Production safety

No live Gmail/Calendar/GitHub polling in tests. No production `jarvis:watchers:prune` without dry-run. No public webhooks. `QUEUE_CONNECTION=sync` in phpunit.

## Deferred validation

E.2 added to [DEFERRED_VALIDATION.md](../DEFERRED_VALIDATION.md) as **IMPLEMENTED / NOT VALIDATED**. Owner is not asked to test now. Not MANUAL PASS.

## Known limitations

No GitHub/Gmail push/webhooks (polling). No Zapier builder. No silent external writes. Semantic “importance” is allowlisted + optional bounded analysis, not a full classifier. Aggregation is a simple time window. Phase E is not complete.

## Next Phase

**E.3** (not this milestone): cross-source synthesis / people intelligence depth / richer project intelligence, or confirmed external actions, based on remaining gaps. E.1 and E.2 stay IMPLEMENTED / NOT VALIDATED until Owner live campaigns.
