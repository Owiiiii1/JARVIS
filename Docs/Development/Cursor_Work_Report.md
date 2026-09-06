# Phase B.2 — Tasks & Proactive

## Starting HEAD

- Baseline: `88854d7e7d1ade26f6e403de591a8cf269887c0d` `fix: restore GetAssistantProfileTool import for ToolRegistry`
- Parent of Reminders 2.0: `1a76877` `feat: add Reminders 2.0 and Web Push`
- Working tree was clean; `HEAD == origin/main` before work.

## Schema changes

Additive only:

1. `tasks` — Core task rows (`user_id`, `parent_task_id`, title, description, status, priority, `due_at` UTC, timezone, source conversation/message, optional `project_id`, optional calendar reference columns, `completed_at`, `cancelled_at`, bounded metadata).
2. `reminders.task_id` nullable FK → `tasks` (`nullOnDelete`).
3. `jarvis_notifications` — in-app inbox with unique `(user_id, dedupe_key)`.
4. `user_productivity_settings` — per-user opt-in brief/proactive clocks.

Migrations:

- `2026_09_06_111732_create_tasks_table`
- `2026_09_06_111733_add_task_id_to_reminders_table`
- `2026_09_06_111734_create_jarvis_notifications_table`
- `2026_09_06_111735_create_user_productivity_settings_table`

## Tasks domain

Statuses: `open`, `in_progress`, `completed`, `cancelled`.
Priorities: `low`, `normal`, `high`, `urgent`.

`TaskService` / `TaskLifecycle`: create, list/search, get owned, update, start, complete, cancel, reopen (completed → open), priority, due date, project (Owner), calendar reference, subtasks.

## Subtasks

`parent_task_id` self-FK. Same user. One level only. Completing a parent with unfinished children returns `open_subtasks` unless `force=true`. Children are not deleted or auto-completed.

## Task ↔ Reminder

`reminders.task_id` nullable. A reminder without a task still works. Completing or cancelling a task cancels **future open** linked reminders (including a recurring series on that row). Delivered/history rows remain.

## Task ↔ Conversation

`source_conversation_id` / `source_message_id`. Only owned conversations. Task Center links to Workspace chat. Arbitrary client/LLM conversation ids are rejected.

## Project / Calendar relations

- Owner: optional `project_id` of an owned project. Ordinary users cannot set or see project controls (`can_use_projects` false).
- Optional Google event reference columns. Task create does not require Google. Calendar disconnected: Tasks still work. External Calendar writes stay on existing Google tools + confirmation policy. No local event mirror.

## Task Center

Header **Задачи** (icon + active count; mobile icon/badge) on `/jarvis` and `/chat`. Drawer consistent with Reminder Center. Sections: Просрочено, Сегодня, Предстоящие, Без срока, Выполненные. Manual create (title, due, priority, description). Actions: Start, Complete, Edit, Cancel, add subtask.

## Notification Center

Header **Уведомления** (Inbox icon + unread badge), distinct from **Напоминания**. All / Unread, mark read, dismiss, safe `/jarvis` or `/chat` action URL. Owner and ordinary users see only their rows.

Reminder due also writes an inbox row **without** a second Telegram/Web Push (Reminders already deliver). Task/brief/proactive may optionally Web Push via existing `SendsWebPush`. Push failure does not delete the inbox row. Telegram is not used for Notification Center spam.

## Daily Brief

Opt-in. Default off. Local time default `08:00` in the user timezone. Source-grounded: owned tasks, reminders, Owner projects, recent notifications. Optional bounded LLM phrasing. AI failure → deterministic fallback (never empty). Delivery: Notification Center + optional Web Push. Not Telegram by default.

## Evening / Weekly Review

Same `ProductivityBriefService` modes `evening` / `weekly`. Defaults off. Evening `20:00`. Weekly Sunday (`weekday=7`) `18:00`. Same opt-in + fallback rules.

## Proactive Engine

Deterministic triggers only (overdue task; high/urgent due within 2 hours). LLM may rephrase; trigger is never “ask the model what to suggest”. AI failure still delivers the deterministic sentence. No external writes.

## Anti-spam rules

- `proactive_enabled` default **false**
- max **3** `proactive_suggestion` / local day (excludes `reminder_due` and `brief_ready`)
- cooldown **4 hours** per task source
- unique `dedupe_key`; identical suggestion is not repeated

## AI tools

`create_task`, `list_tasks`, `get_task`, `update_task`, `start_task`, `complete_task`, `cancel_task`, `create_subtask`, `link_task_reminder`. Conservative create policy. Ambiguity returns candidates. Never pass `user_id`.

LLM used for: optional brief phrasing, optional proactive phrasing. **Not** used for trigger decisions, due detection, or inbox persistence.

## Context integration

Bounded Productivity snapshot in the conversation system prompt only when overdue/due-today/urgent exist (counts + up to 3 titles). Deep queries via tools. Tasks are not auto-written to Memory.

## Ownership / isolation

Backend `user_id` is authoritative. Ordinary users: personal tasks/notifications/briefs/proactive. No Owner Projects / Gmail / Calendar / GitHub / groups. Foreign conversation, project, and task ids 404/`not_found`.

## Migrations

See Schema changes. All `up()` additive.

## Tests

Isolated PHPUnit (in-memory models / fakes; no production `RefreshDatabase`):

- `tests/Unit/Tasks/*`
- `tests/Unit/Notifications/NotificationInboxTest.php`
- `tests/Unit/Productivity/ProductivityEngineTest.php`
- ToolRegistry includes task tools

## Build/static checks

`php -l`, Pint dirty, `composer validate`, `npm run build`, `git diff --check`, `route:list`, `migrate:status` after additive migrate. No live Telegram / Web Push / Gmail / Calendar writes / Gemini / ElevenLabs during implementation.

## Production safety

No `migrate:fresh`, no truncate, no mass delete of Owner data, no live provider calls from tests. Production DB `jarvis` received additive `php artisan migrate` only.

## Documentation

Updated: TASKS_AND_PRODUCTIVITY.md, TASKS.md, NOTIFICATIONS.md, ROADMAP.md, CURRENT_STATE.md, IMPLEMENTATION_PLAN.md, DATABASE.md, ARCHITECTURE.md, CONVERSATION_ENGINE.md, REMINDERS.md, DECISIONS.md, this report.

## Known limitations

- Calendar is not polled every 5 minutes for proactive conflict detection (avoids live Google spam); briefs remain source-grounded without requiring Calendar.
- Repeatedly snoozed **task** rule is not a separate counter (tasks have no snooze); linked reminder snooze stays in the Reminder domain.
- Subtasks are one level deep.
- Phase B.2 is **not** Owner live-validated.

## Phase status

- Phase B.1 Reminders 2.0: Owner **MANUAL PASS for confirmed live core flow** (Web Push, Reminder Center, basic user flow). Not exhaustive edge-case MANUAL PASS.
- Phase B.2: **IMPLEMENTED / NOT VALIDATED**
- Phase B overall: **IMPLEMENTED / awaiting Owner validation**

## Owner manual checklist

A. TASK — «Создай задачу отправить отчёт завтра до 16:00» → appears in Task Center.
B. LINKED REMINDER — «Напомни мне про эту задачу завтра в 10» → linked reminder.
C. COMPLETE — «Я отправил отчёт» → task completed; future linked reminder no longer active; history remains.
D. MANUAL UI — Create / Edit / Start / Complete / Cancel.
E. SUBTASK — add a subtask; hierarchy visible; complete parent with open child requires confirm.
F. NOTIFICATION CENTER — due task → one inbox row, not repeated every scheduler tick.
G. DAILY BRIEF — enable → brief contains actual task/reminder data (or deterministic fallback).
H. PROACTIVE — enable suggestions, create urgent near-due task → bounded alert (max 3/day).
I. USER ISOLATION — ordinary user sees only own tasks/notifications.
J. OWNER — Owner can link task to owned Project; ordinary user has no project controls.
