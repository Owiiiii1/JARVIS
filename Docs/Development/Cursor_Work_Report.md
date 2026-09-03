# Cursor work report — Structured Memory / Memory Engine v1

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `b9f7cad` (`fix: stabilize Telegram reminder tool turns`) |
| Commit message | `feat: add structured personal memory engine` |
| Working tree before start | clean |

## Backup

Before migration: `storage/backups/pre_memory_20260903_203935.sql` (gitignored). Tables: `users`, `conversations`, `messages`, `ai_role_settings`, `user_ai_settings`, `reminders`, `channel_identities`. Production chats retained. Raw messages were not deleted or rewritten.

## Migrations

`2026_09_03_220000_create_memory_engine_tables` — ran with `--force`.

Tables: `conversation_summaries`, `topics`, `message_topic_relations`, `memories`, `memory_sources`, `memory_revisions`, `user_profiles`, `memory_analysis_runs`.

## Queue worker / systemd

System-wide `/etc/systemd/system` install needs sudo (password required on this host). A lingering user unit is active instead:

- unit: `~/.config/systemd/user/jarvis-queue.service`
- command: `php artisan queue:work database --queue=memory,default --sleep=3 --tries=3 --timeout=120 --max-time=3600`
- user: `deploy`
- Restart=`always`
- Linger=`yes`
- status: `active (running)`
- journal: started without errors at enable time

Telegram queue stays on the existing crontab flock worker (`--queue=telegram`). Reminder scheduler cron is unchanged. No Supervisor. No duplicate memory/default worker.

Repo copy of the system unit: `deploy/jarvis-queue.service`.

## Jobs

- `AnalyzeConversationTurnJob` — Owner Analysis AI extract; idempotent via `memory_analysis_runs`
- `UpdateConversationSummaryJob` — incremental summary at threshold or `--force`
- Dispatch after successful conversation persist, not before the user reply

## Summary strategy

Config `memory.summary_message_threshold` (default 20 semantic messages since last summary). Incremental: previous summary + raw after `to_message_id`. Initial long history: chunk/reduce. Versions kept; `current` / `superseded`. Raw unchanged.

## Extraction strategy

Owner Analysis AI only. Structured JSON DTO (`MemoryAnalysisResult`), validated before write. Core assigns `user_id`. Explicit “запомни” is in the analysis prompt, not regex business logic. Provenance required. Reinforce updates `last_confirmed_at` / confidence / sources. Supersede writes revision trail and keeps the old row.

## Retrieval strategy

`PersonalMemoryRetriever`: SQL `WHERE user_id = current` first, then keyword / `normalized_key` / freshness / confidence / validity. No vector DB. Cross-chat: relevant summaries only, not raw. Expired, low-confidence, disputed, superseded, obsolete excluded from current truth.

## Context limits (`config/memory.php`)

- memories max 10
- fallback memories 5
- cross-chat summaries max 5
- min confidence 0.65
- recent current-chat messages = existing ConversationContextBuilder limit
- search snippets max 8

## Search history tool

`search_conversation_history` in Tool Registry alongside `create_reminder`. Capability `memory`. Arguments: query, optional conversation hint, optional limit. Core uses current user. Bounded snippets. Foreign conversations denied.

## Isolation model

Topics, memories, summaries, retrieval, and history search are always scoped by `user_id`. Topic names are unique per user, not globally. Group knowledge not implemented.

## Dedupe / revisions

Dedupe key: `user_id` + kind + `normalized_key` for active rows. Reinforce does not insert a second row. Contradiction → supersede + revision + new/updated fact.

## Tests

`php artisan test`: 107 tests, 106 passed, 1 skipped, 471 assertions. Fake Analysis AI only. Temporary `jarvis-test-*@invalid.local` rows cleaned by id. Production owner memories were not written by automated tests.

## Build

`npm run build` succeeded (Settings → Users Memory diagnostics page).

## Migrate / queue / schedule

- `php artisan migrate:status` — memory migration ran
- `php artisan queue:failed` — none
- `php artisan schedule:list` — `jarvis:reminders:dispatch` every minute

## Production counts (after migrate, before owner smoke)

| Table | Count |
| --- | --- |
| users | 1 |
| conversations | 2 |
| messages | 27 |
| reminders | 11 |
| conversation_summaries | 0 |
| topics | 0 |
| memories | 0 |
| memory_analysis_runs | 0 |
| jobs | 0 |
| failed_jobs | 0 |

## Backfill dry-run

`php artisan jarvis:memory:backfill --dry-run --limit=20 --chunk=5`

Result: 2 conversations, 10 bounded turn candidates, 0 summaries (below threshold). Not dispatched. Full owner backfill was not run automatically.

## Manual smoke

Awaiting owner. Suggested checks (do not invent personal facts):

1. Worker active (already verified).
2. In Telegram, send a durable test fact, then wait for background analysis.
3. Confirm a memory row + provenance in Settings → Users → Memory.
4. New chat: ask the fact; answer should come from structured memory, not raw of the old chat.
5. Optional: force a summary, ask about an old decision in a new chat, then a detail that needs `search_conversation_history`.

## Known issues

- `/etc/systemd/system/jarvis-queue.service` was not installed (sudo password). User lingering unit is the production worker.
- Owner history is not backfilled until an explicit non-dry-run command.
- Profile summary generation waits until enough high-confidence memories exist.
- No cabinet Memory UI (by design for M12).
- No group knowledge / Projects / vector DB.

## Next milestone

Milestone 13 — Projects (Owner Space containers; Project ≠ Topic).
