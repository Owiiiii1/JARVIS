# Notification Center

Persistent in-app inbox (`jarvis_notifications`). Distinct from Reminder Center and from Web Push transport.

**Status.** Phase B.2 IMPLEMENTED / NOT VALIDATED.

Types: `reminder_due`, `task_due`, `task_overdue`, `brief_ready`, `proactive_suggestion`.

Dedupe key is unique per user. Safe action URLs are `/jarvis` or `/chat` only.

See [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).
