# Tasks, productivity, and proactive Jarvis

**Status.** PLANNED (Phase B / E). Not implemented. No task tables in this documentation pass.

Related: [REMINDERS.md](REMINDERS.md), [ROADMAP.md](ROADMAP.md).

---

## Reminder vs Task

| | Reminder | Task |
| --- | --- | --- |
| Question | When should Jarvis notify me? | What do I need to accomplish? |
| Today | Core table `reminders`; Telegram-gated | **Does not exist** |
| Target | Channel-independent; Web + optional Telegram / Push | Own domain with status, deadline, relations |

A task may have:

- status
- deadline
- subtasks
- related conversation
- project
- files
- one or more reminders

A reminder may later point at a task. They are not the same row.

---

## Notification Center (future)

A single in-workspace inbox for:

- due reminders
- tool/watch events the user opted into
- brief summaries the user requested

Web Push / browser notifications are a **transport**, not the Center itself. Mobile push is Phase D.

---

## Calendar ↔ Tasks ↔ Reminders

Google Calendar is a live Owner integration today. It is not the Reminder Engine.

Target: explicit relationships (event ↔ task ↔ reminder) without mirroring Google into a second calendar database.

---

## Daily Brief / Weekly Review (future)

**Daily Brief** may synthesize, grounded in sources:

- calendar
- active tasks
- reminders
- project state
- relevant overnight / new events

**Weekly Review:**

- completed work
- unresolved tasks
- project changes
- upcoming deadlines

Must be source-grounded, not a freeform invented recap.

---

## Proactive Engine (future)

Proactive does **not** mean unsolicited generic AI chatter.

Driven by:

- deadlines
- reminders
- tasks
- calendar events
- monitored external events
- explicit user opt-in
- meaningful detected changes

Must have:

- anti-spam rules
- user controls
- auditability
- permissions
- a clear trigger source

Risky external writes still require confirmation.

---

## Personal Knowledge Graph (future, optional)

Optional structured layer over existing Memory Engine and raw sources. **Does not replace** Memory or conversation history.

Potential entities: Person, Company, Project, Event, Task, File, Conversation, Reminder.

Relationships and provenance must trace to source data.

---

## People / Contacts (future)

A Person entity may later aggregate: saved contact, conversation mentions, projects, meetings, email relations, tasks.

Not implemented. Do not invent a contacts product from `user_profiles.summary`.

---

## Automations / watchers (future)

Event-driven layer. Examples: email arrival, GitHub change, deadline approaching, reminder condition.

Prefer:

- webhook / event sources where possible
- scheduled watches where necessary
- explicit opt-in
- confirmation for risky external writes

Do not implement uncontrolled polling everywhere.
