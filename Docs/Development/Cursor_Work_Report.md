# Cursor work report — Owner Projects

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `a33468d` (`feat: add structured personal memory engine`) |
| Commit message | `feat: add owner project containers` |
| Working tree before start | clean |

## Backup

Before migration: `storage/backups/pre_projects_20260903_211039.sql` (gitignored). Tables: `users`, `conversations`, `conversation_summaries`, `topics`, `memories`, `memory_sources`, `reminders`, `ai_role_settings`. Existing owner memory was not rewritten. No auto-created JARVIS/YFS/RTS projects.

## Migration / schema

`2026_09_03_230000_create_project_tables` ran with `--force`.

- `projects`: `user_id`, `name`, `normalized_name`, `description`, `status` (`active`/`archived`), `metadata`, unique `(user_id, normalized_name)`
- `project_conversations`, `project_topics`, `project_memories`: unique pair, `attached_at`, cascade pivot only
- `project_groups` not created (Telegram Groups subsystem does not exist)

## Relations

Project is a relation container. Conversations, topics, and memories stay in their tables. One entity may attach to multiple projects. Memory `scope` remains `personal`.

## ProjectService

Channel-neutral create/update/archive/restore/list/attach/detach with `user_id` ownership checks and `projects` capability. Foreign conversation/topic/memory rejected. Detach does not delete the entity.

## Authorization

`ProjectPolicy` plus owner middleware. Foreign project URL → 404. Regular user admin routes → 403. Capability `projects` is owner-only (unchanged default sets).

## Admin UI / routes

Owner navigation: Projects. List, create, show, edit, archive/restore, attach/detach conversations/topics/memories. Groups noted as later. Settings → Users has no Projects section.

## Context retriever

`ProjectContextService`: project metadata, attached topics, attached active memories, current conversation summaries. Bounded via `config/projects.php`. Query ranking is optional. No Analysis AI on retrieve. No raw dump of attached chats.

## `get_project_context` tool

Owner-only. Arguments: `project`, optional `query`. Core uses `ToolExecutionContext.user`. Exact then bounded name match; ambiguous returns candidates. Archived projects are not resolved as active.

## Tool capability filtering

Owner: `create_reminder`, `search_conversation_history`, `get_project_context`.  
User: reminder + history search only.  
Default conversation prompt does not include all projects.

## Limits

`config/projects.php`: max memories 10, topics 10, summaries 5, search 10, description 5000.

## Tests

`php artisan test`: 113 tests, 112 passed, 1 skipped, 526 assertions. Fake AI only. Temporary `jarvis-test-*` users; HTTP create used a uniquely named owner project that was deleted after the test.

## Build

`npm run build` (required for Admin Projects UI).

## Production counts (after migrate, before owner smoke)

| Table | Count |
| --- | --- |
| projects | 0 |
| project_conversations | 0 |
| project_topics | 0 |
| project_memories | 0 |
| memories | 1 |
| topics | 1 |
| conversations | 2 |
| messages | 31 |
| reminders | 11 |
| failed_jobs | 0 |

Memory/default worker: `active (running)`. Telegram crontab worker unchanged. Reminder scheduler listed.

## Manual smoke

Awaiting owner: create `JARVIS`, attach conversation/topic/memories, ask in Telegram «Что у нас сейчас по проекту JARVIS?», then empty `TEST` project.

## Known issues

- `project_groups` deferred until Groups subsystem.
- No automatic message→project classification.
- No AI attach tool in M13 (Admin attach only).
- System-wide `/etc/systemd/system/jarvis-queue.service` still not installed (user lingering unit remains the memory/default worker).

## Next milestone

Milestone 14 — Group Analysis (depends on Groups). Or Milestone 11 — Telegram Groups, if Groups must land first.
