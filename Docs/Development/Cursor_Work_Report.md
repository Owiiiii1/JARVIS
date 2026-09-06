# Product Validation — Core Daily Workflow Preparation

## Starting HEAD

`843dd3a51176c486301d7c2ffd9b5b5b038282bd` (`docs: add Jarvis system overview`), clean and equal to
`origin/main`. One untracked file left over from the previous turn (`Docs/JARVIS_PITCH.md`) was committed
separately as `575fc78 docs: add short product pitch` before this work started, so the campaign changes sit on
a clean tree.

## Scope

Not a product phase. No new features. The task was to prepare and technically verify **one sequential manual
validation runbook** for the Owner, covering the core daily chain end to end: Conversation → Task → Reminder →
internal Watcher → Knowledge → Synthesis → Overview → Notification → state change → chat delete.

Out of scope and untouched: Gmail live, Google Calendar live, GitHub live, ElevenLabs realtime Beta, Telegram
Groups, destructive Storage, historical retry/prune, Mobile, external watcher polling adapters.

## Systems inspected

Read-only audit across both halves of the chain.

Backend: `JarvisWorkspaceController`, `JarvisWorkspaceStatusController`, `JarvisSynthesisController`,
`JarvisKnowledgeController`, tasks / reminders / watchers / notifications controllers, `routes/owl-admin-pages.php`
(the shared `/jarvis` + `/chat` registrar), `TaskService`, `TaskLifecycle`, `ReminderService`,
`ReminderLifecycle`, `WatcherService`, `WatcherEvaluationService`, `WatcherEvaluationDispatcher`,
`TaskWatcherSource`, `KnowledgeIngestionService`, `KnowledgeRetriever`, `CrossSourceSynthesisService`,
`SynthesisFactCollector`, `SynthesisCache`, `WaitingForResolver`, `CommitmentLifecycle`, `UserCapabilities`,
and the AI tools under `app/Services/Tools/{Tasks,Reminders,Watchers,Knowledge,Synthesis,Projects}`.

Frontend: `PersonalWorkspace.jsx`, `OverviewPanel.jsx`, `TasksPanel.jsx`, `RemindersPanel.jsx`,
`WatchersPanel.jsx`, `NotificationsPanel.jsx`, `settings/WorkspaceSettings.jsx`, `named.js`.

## Static findings

Seven real defects, all in the seams between subsystems — exactly where a per-subsystem test suite does not
look, and exactly what this campaign is meant to exercise.

1. `ReminderService::create()` stored a caller-supplied `task_id` with no ownership check; `linkOwnedTask()`
   validated the reminder but not the task. `CreateReminderTool` passes a model-supplied id straight through,
   so a hallucinated or foreign id could become a real foreign key.
2. `ReminderService::updateOwned()` was the only reminder mutator that did not notify watchers, so editing or
   rescheduling a reminder left the synthesis cache stale for up to the TTL.
3. `WatcherService::updateOwned()` did not bump the synthesis cache, unlike create / pause / resume / cancel.
4. `WatcherService::resolveRefs()` validated ownership of project, entity, task, and reminder ids but not
   `integration_account_id`.
5. A one-shot task watcher whose condition requires an open task (`overdue_by`, `deadline_within`,
   `status_equals` on an open status) never fired once the task was closed and stayed `Active` forever —
   inflating the watcher badge and showing permanently under Overview → «Жду». This is precisely the check in
   Scenario 7.
6. Knowledge entity and relationship upserts only invalidated the synthesis cache indirectly, via
   `recordEvent` and only when the event fingerprint was new.
7. `JarvisSynthesisController::index()` accepted a `project_id` with only the `knowledge` capability asserted.

Frontend: `OverviewPanel` kept rendering the previous payload underneath the spinner while refetching, had no
"Today" section despite the documented contract, and was not refreshed when a mutation happened in another
panel stacked on top of it.

## Bugs fixed

| Area | Fix |
| --- | --- |
| Ownership | `ReminderService` resolves `task_id` through a new `ownedTaskId()` guard — an unowned id is dropped (and logged as a bounded warning) on create, and rejected as `not_found` on an explicit `linkOwnedTask`. |
| Ownership | `WatcherService::resolveRefs()` rejects an `integration_account_id` the caller does not own. |
| Ownership | `JarvisSynthesisController::index()` requires the `projects` capability when `project_id` is present. |
| Cache | `ReminderService::updateOwned()` notifies watchers, which bumps the per-user synthesis version. |
| Cache | `WatcherService::updateOwned()` bumps the synthesis version when it actually changed something. |
| Cache | `KnowledgeIngestionService` bumps the synthesis version after entity and relationship upserts. |
| State | `WatcherEvaluationDispatcher::afterTaskChanged()` finishes one-shot open-dependent task watchers when the task closes, recording `cursor.resolved_reason = task_closed`. `status_changed` watchers are untouched and still fire on the transition. |
| State | `WaitingForResolver::staleWatcherIds()` skips watchers whose linked task is already closed, in both waiting-for and open-loops. This covers rows left Active by an earlier release without a data migration. |
| UI | `OverviewPanel` clears the old payload before refetching, hides the lists while loading or on error, and renders a «Сегодня и ближайшее» section from the already-computed `upcoming` slice. |
| UI | Tasks / Reminders / Watchers panels report successful mutations through a new `onDataChange` callback; `PersonalWorkspace` bumps a dedicated token so an open **Обзор** refreshes without re-fetching the panel that just mutated. |
| Test defect | Two `ReminderServiceTest` cases hardcoded `2026-09-06 12:00:00` as a future instant with no `travelTo`, so they began failing the moment wall-clock time passed noon today. Both now pin the clock. |

No new product features. No new dependencies. No schema change, no migration, no data backfill.

## Ownership review

Static only. No second live user was created and no hostile IDOR campaign was run — that stays deferred.

Verified as correctly guarded: chats, attachments, tool confirmations, tasks, reminders, watchers,
notifications, knowledge entities, synthesis (`index` / `project` / `entity`), workspace status, voice
sessions, push subscriptions, and Owner-only Storage. Every id-taking handler resolves the row through a
`user_id`-scoped query or an `ensureOwned` / `requireOwned` / `findOwned` helper before reading or mutating;
foreign ids return 404 or `not_found` rather than data.

The Owner role grants **capabilities**, not a bypass of row-level scoping: `/jarvis` and `/chat` share the
same handlers, differing only in the workspace-redirect middleware and Owner-only Storage routes, so `/chat`
is not more permissive than `/jarvis`. AI tools cannot pass `user_id`, `authorized`, or
`integration_account_id` as an authorization signal — `ToolConfirmationPolicy` strips them and ids supplied by
the model are re-resolved against the caller's own candidate rows. Synthesis fact collection filters every
domain by the scope user and returns Owner Projects only with the `projects` capability.

Three gaps existed and are fixed above (reminder task link, watcher integration account, synthesis
`project_id` capability). None of them leaked another user's content in the paths inspected; they were
integrity and defence-in-depth holes.

## UI wiring review

Panels open independently and stack as overlays, so **Обзор** can sit behind **Задачи**. Before this change a
task completed in the Tasks panel updated that panel and the header badge but left the Overview behind it
stale until the next chat turn. Fixed via `onDataChange`. Overview already refetched on open, on surface
change, and after a chat turn; those paths were correct and are unchanged. Route resolution through
`workspaceRoute(surface, …)` correctly targets both the `jarvis.*` and `chat.*` names.

## Cache/state review

The synthesis cache is a per-user version bump, so correctness depends on every mutation path bumping it.
Enumerated all of them. Tasks (create / update / start / complete / cancel / subtask / reopen), reminders
(create / cancel / complete / snooze), knowledge events with a new fingerprint, watchers (create / pause /
resume / cancel / trigger), and projects (create / update / archive / restore) already did. Reminder update,
watcher update, and knowledge entity/relationship upserts did not, and now do.

Task completion side effects confirmed: open linked reminders are cancelled, matching open commitments are
fulfilled by `metadata.task_id`, a `TaskCompleted` knowledge event is recorded, and task-linked watchers are
re-evaluated. Cancellation cancels linked reminders but deliberately does **not** fulfil commitments or write
a completion event — cancelling a task is not delivering on a promise. Left as is; recorded here so the Owner
is not surprised by it during Scenario 7.

Watcher baselining verified: a new watcher starts with `cursor.baseline_established = false` and the first
evaluation records fingerprints without firing, so Scenario 3 should not produce an immediate notification.

## Diagnostics review

The goal was that a manual FAIL can be reported with route, timestamp, safe entity id, exception class, and a
bounded error code — without copying messages, prompts, provider bodies, or secrets.

Domain exceptions already surface bounded codes to the panels (`not_found`, `capability_denied`,
`invalid_config`, `past_time`, …), and tool runs are recorded in `tool_execution_logs`. The one gap was an
unexpected `Throwable` inside a synthesis request, which produced a bare 500 with nothing correlatable.
`JarvisSynthesisController` now funnels all three actions through one `respond()` helper that logs route, user
id, synthesis type, project/entity id, and exception class, and returns the code `synthesis_failed`. The
rejected reminder→task link also logs a bounded warning with the two ids and a reason.

No message content, prompt, or provider payload is written by any of the added logging.

## Validation runbook

New: [`Docs/VALIDATION_CORE_WORKFLOW.md`](../VALIDATION_CORE_WORKFLOW.md).

Contains scope in/out, rules of engagement, the timing facts that matter while testing (async extraction
window, watcher cadence, cache behaviour), a preflight, ten scenarios, the ownership summary, the safe
diagnostics table, a manual cleanup section, and an explicit statement of what a full pass does and does not
close. Every scenario carries the required nine fields: Purpose, Preconditions, Owner action, Expected UI,
Expected backend state, Do NOT inspect, Pass criteria, Failure capture, Cleanup.

## Scenarios ready

All ten are `READY FOR OWNER VALIDATION`. None is PASS.

| # | Scenario | Status |
| --- | --- | --- |
| 1 | Conversation continuity | READY |
| 2 | Task + Reminder | READY |
| 3 | Internal watcher | READY |
| 4 | Knowledge | READY |
| 5 | Synthesis | READY |
| 6 | Waiting / commitments | READY |
| 7 | State change | READY |
| 8 | Overview | READY |
| 9 | Memory vs Knowledge | READY |
| 10 | Chat delete regression | READY |

## Checks run

| Check | Result |
| --- | --- |
| `php -l` on every touched PHP file | clean |
| `vendor/bin/pint --dirty` | clean |
| `composer validate` | valid |
| `npm run build` | success |
| `git diff --check` | clean |
| `php artisan route:list` | resolves |
| `php artisan migrate:status` | all Ran, nothing pending |
| `php artisan schedule:list` | unchanged |
| Targeted tests (reminders, watchers, synthesis, knowledge, conversations, workspace delete, projects) | 92 passed |

Six regression tests were added for the fixes: reminder task links restricted to owned tasks, reminder edit
invalidates cached synthesis, one-shot task watcher resolved on task close, `status_changed` watcher still
fires on that same close, watcher rejects an unowned integration account, waiting-for ignores a watcher whose
task is already closed, and the synthesis list route rejects `project_id` without the `projects` capability.

**Pre-existing suite failures, not caused by this work.** The full suite reports 14 failures and 3 errors.
Every one of them reproduces identically on a stashed clean tree at the starting HEAD, verified by running the
same files with the changes removed. They are environment-coupled tests (production Owner has integrations
connected, capability data sets, admin route expectations, Telegram settings route) plus the two clock-bomb
reminder cases, which this change fixes. The remaining 12 are outside the campaign scope and were left alone
rather than expanding the diff.

## Production safety

Nothing was executed against production data. No `migrate:fresh`, no `RefreshDatabase` against production, no
truncate, no bulk delete or retry, no live provider call, no Gmail / Calendar / GitHub request, no Telegram
send, no ElevenLabs or Gemini smoke test. No production records were created to simulate the campaign — the
Owner runs it by hand. The automated tests create and delete their own temporary users through the existing
`CleansTemporaryJarvisRecords` trait, as every previous milestone did.

## Deferred items

The backlog was not emptied. [`DEFERRED_VALIDATION.md`](../DEFERRED_VALIDATION.md) gains a section describing
this campaign and stating precisely which rows may be narrowed **if** it passes: B.2 core productivity flow,
E.1 core Knowledge flow, E.2 internal watcher flow, E.3 synthesis core flow, and the C.1 behaviours actually
exercised in Scenarios 1–2. Everything else stays deferred, including external watcher campaigns, C.2,
Google/GitHub, Telegram Groups, DST and recurrence edges, destructive Storage, historical retry/prune, Mobile,
and the two-user IDOR campaign.

## Owner manual actions required

1. Read `Docs/VALIDATION_CORE_WORKFLOW.md` §2 (rules) and §4 (preflight).
2. Run Scenarios 1 → 10 in order, in the production Owner Workspace, top to bottom in one sitting. Scenario 10
   deletes the campaign chat, so record results in the matrix before it.
3. For each scenario, write `MANUAL PASS`, `MANUAL PARTIAL`, or `LIVE BUG` into §3, with the safe capture
   fields from §15 for anything that failed.
4. Report the results. Only then will the matrix, `CURRENT_STATE.md`, and the deferred backlog be updated —
   status stays `READY` until an explicit Owner result exists.
5. Run the manual cleanup in §16 when finished.
