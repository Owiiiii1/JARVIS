# Tasks, productivity, and proactive Jarvis

**Status.** Phase B.2 **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS until Owner live test.

Related: [TASKS.md](TASKS.md), [NOTIFICATIONS.md](NOTIFICATIONS.md), [REMINDERS.md](REMINDERS.md), [ROADMAP.md](ROADMAP.md).

---

## Phase B.1 Reminders 2.0

Owner confirmed the live core flow works: **MANUAL PASS for confirmed live core flow**.

That covers:

- Web Push live works
- Reminder Center live works
- basic Reminder 2.0 user flow works

It does **not** claim MANUAL PASS for every DST / recurrence / multi-device / delivery-failure edge case.

---

## Reminder vs Task vs Watcher vs Proactive

| | Reminder | Task | Watcher | B.2 Proactive |
| --- | --- | --- | --- | --- |
| Question | When should Jarvis notify me at a **known time**? | What do I need to accomplish? | Notify when a **future condition/event** is true | Bounded **heuristic** suggestion |
| Table | `reminders` | `tasks` | `watchers` | `jarvis_notifications` (`proactive_suggestion`) |
| Example | «напомни завтра в 9» | «сделай отчёт» | «если завтра всё ещё не готово» | overdue high-priority task |

A task is **not** a reminder row. Completing or cancelling a task cancels **future open** linked reminders and keeps history (`reminder_occurrences`, delivered rows). A watcher is **not** a reminder: it evaluates a condition. B.2 proactive remains a separate engine and must not be recreated as implicit watchers.

Watchers: [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md).

---

## Tasks

Statuses: `open`, `in_progress`, `completed`, `cancelled`.
Priorities: `low`, `normal`, `high`, `urgent`.

Subtasks: `tasks.parent_task_id`, one level, same user. Completing a parent with open children returns `open_subtasks` unless `force=true`. Children are not destroyed.

Optional relations:

- `source_conversation_id` / `source_message_id` (owned conversation only)
- `project_id` (Owner + owned project; ordinary users always null)
- calendar reference `calendar_provider` / `calendar_id` / `calendar_event_id` (optional; not a Calendar mirror)

Task Center: header **Задачи** on `/jarvis` and `/chat`. Sections: Просрочено, Сегодня, Предстоящие, Без срока, Выполненные.

---

## Notification Center

In-app inbox table `jarvis_notifications`. **Not** a second Web Push stack.

Types: `reminder_due`, `task_due`, `task_overdue`, `brief_ready`, `proactive_suggestion`.

Dedupe: unique `(user_id, dedupe_key)`. Scheduler ticks do not spam.

Web Push may accompany task/brief/proactive rows via existing VAPID infrastructure. Push failure does not delete the inbox row. Telegram is **not** used for Notification Center events by default. Reminders keep current Telegram behavior.

---

## Briefs and reviews

Per-user opt-in in Workspace settings (Productivity). Defaults: **all off**, including Owner.

| Mode | Default local time | Command |
| --- | --- | --- |
| Daily Brief | 08:00 | `jarvis:briefs:dispatch` every minute |
| Evening Review | 20:00 | same |
| Weekly Review | Sunday 18:00 (`weekday=7`) | same |

Sources gathered first (owned tasks, reminders, Owner projects, recent notifications). Phase E.3 optionally adds bounded synthesis: waiting-for, commitments, project changes, top attention items. Optional bounded LLM phrasing. If AI fails: deterministic fallback text is still delivered. No second Daily Brief system. [CROSS_SOURCE_SYNTHESIS.md](CROSS_SOURCE_SYNTHESIS.md).

Calendar events may appear in a Daily Brief only when Google Calendar capability exists; a disconnected calendar does not break Tasks. Brief dispatch does **not** poll Google every 5 minutes.

---

## Proactive Engine

Deterministic Core decides the trigger. LLM may only rephrase.

Allowed B.2 triggers:

- open task becomes overdue
- high/urgent task due within 2 hours

E.3 may add closed extra types through the same dispatcher: `follow_up`, `project_blocked`, `waiting_too_long`, `deadline_risk`, `stale_project`, `commitment_due`. Same opt-in, daily cap, cooldown, quiet hours. No new notification stream.

Anti-spam (actual values):

- `proactive_enabled` default **false**
- max **3** `proactive_suggestion` rows per local day (excludes reminders and briefs)
- cooldown **4 hours** per task source
- unique `dedupe_key` (e.g. `proactive:task_overdue:{id}`)

Scheduler: `jarvis:proactive:dispatch` every 5 minutes.

Watchers are a different product: an **explicit** persisted condition over a source. They also deliver through Notification Center / Web Push. Do not duplicate B.2 heuristics as watchers. [WATCHERS_AND_AUTOMATIONS.md](WATCHERS_AND_AUTOMATIONS.md).

No unsolicited chatter. No external writes.

---

## Schedulers

| Command | Frequency |
| --- | --- |
| `jarvis:reminders:dispatch` | every minute |
| `jarvis:tasks:dispatch` | every 5 minutes (due / overdue inbox) |
| `jarvis:briefs:dispatch` | every minute (opt-in clocks) |
| `jarvis:proactive:dispatch` | every 5 minutes |
| `jarvis:watchers:dispatch` | every 5 minutes (due watchers only) |

Task due keys: `task_due:{id}:{Y-m-d}`, `task_overdue:{id}`.

---

## AI tools

`create_task`, `list_tasks`, `get_task`, `update_task`, `start_task`, `complete_task`, `cancel_task`, `create_subtask`, `link_task_reminder`.

Conservative create policy: explicit request or unambiguous commitment. Ambiguous matches return candidates. Never pass `user_id`.

Context injection: bounded snapshot (overdue count, due-today count, up to 3 high/urgent titles) only when those counts are non-zero. Deep queries use tools. Tasks are **not** written to Memory.

---

## Ordinary user vs Owner

Ordinary user: personal Tasks, subtasks, linked reminders, Task Center, Notification Center, opt-in briefs, opt-in proactive.

Ordinary user **does not** get Owner Projects, Gmail, Calendar, GitHub, groups/admin.

Owner may link a task to an owned Project and optionally store a Google event reference. Calendar **writes** stay on existing Google tools + confirmation policy.
