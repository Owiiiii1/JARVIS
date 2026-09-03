# Cursor work report — Group Analysis (Milestone 14)

Date: 2026-09-04  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `06bcbd7` (`feat: add Telegram groups monitoring`) |
| Commit message | `feat: add Telegram group analysis` |
| Working tree before start | dirty (M11 follow-ups: group archive, clickable rows, membership sync) — included in this commit |

## Backup

Before migration: `storage/backups/pre_group_analysis_20260903_233238.sql` (gitignored). Tables dumped: `telegram_groups`, `telegram_group_participants`, `conversations`, `messages`, `projects` / `project_groups`, `memories`, `topics`, `ai_role_settings`, `reminders`. Production raw group history was not deleted.

## Migrations / schema

`2026_09_04_010000_create_telegram_group_analysis_tables` ran with `--force` (batch 12). `php artisan migrate --force` afterwards: nothing to migrate. `migrate:status` shows Ran.

New tables:

- `telegram_group_analysis_runs`
- `telegram_group_knowledge`
- `telegram_group_knowledge_sources` (unique `knowledge_id` + `message_id`)
- `telegram_group_knowledge_revisions`

Personal `memories` is not used for group-derived data.

## Knowledge types

`telegram_group_knowledge.type`:

- `summary`
- `decision`
- `task`
- `event_fact`

Status: `active` | `superseded` | `obsolete` | `disputed`.

Owner key is `telegram_group_id`. Even owner-authored group text does not become personal memory.

## Provenance

`telegram_group_knowledge_sources` links each derived row to group `messages`. Decision / Task / Event-Fact require source ids in the analysed group and range. Summary has a message range and/or source links. Foreign or out-of-range source ids fail the job; raw messages are unchanged.

Admin UI source buttons show sender + snippet and highlight the message in the group chat.

## Analysis run model

`telegram_group_analysis_runs`: queued → processing → completed | failed.

- `analysis_type` `range_bundle` (one run yields summary + decisions + tasks + events in a single structured response)
- UTC `from_at` / `to_at`
- `idempotency_key` (group + type + unix range); queued/processing runs are reused
- provider/model, attempts, timestamps, `last_error`, metadata (`no_data`, chunk counts, generated counts)

HTTP Admin returns immediately with flash `Analysis queued`. Laravel `failed_jobs` is not the only status source.

CLI (not executed): `php artisan jarvis:groups:analyze --group= --from= --to= --dry-run`.

## Timezone / range

`GroupTimeRangeService` interprets today / yesterday / last 7 days / custom `Y-m-d` in `telegram_groups.timezone` (fallback owner timezone). DB timestamps stay UTC. DST is handled via IANA/Carbon. Custom range cap: `config/group_analysis.php` `max_range_days` (31).

## Chunk / reduce

`config/group_analysis.php`: max messages/chars per chunk, max chunks per run, max decisions/tasks/events.

Pipeline: retrieve bounded messages → format compact transcript (internal id, local time, display name, text/placeholder, reply/thread) → chunk → Owner Analysis AI per chunk → reduce when chunks > 1 → persist.

Small range: single chunk. Empty range: no LLM call; run completed with `metadata.no_data=true`. Hard max chunks truncates rather than one giant prompt.

## Structured DTO validation

Provider-neutral `GroupAnalysisResult` (`summary`, `decisions[]`, `tasks[]`, `events[]`). `GroupAnalysisPromptBuilder` + `GroupAnalysisResultParser` (not regex). Confidence 0..1; empty content skipped; malformed dates nullable; model-generated user_id ignored; foreign source ids reject the whole output. Failed parse → run `failed`, no knowledge rows.

## Dedupe / supersede

MVP key: `telegram_group_id` + type + `normalized_key` (normalized content). Overlapping analysis reinforces active rows (union provenance, confidence max) instead of duplicating. Later contradicting facts mark the old row `superseded`, write a revision, and insert a new active row (`supersedes_id`). Summary versions per range. Group tasks do not create Reminders.

## Queue changes

Queue name `analysis`. Existing user systemd worker now: `--queue=analysis,memory,default --timeout=180`. Telegram inbound worker stays separate (`--queue=telegram` via crontab flock). No second worker process. `AnalyzeTelegramGroupRangeJob` claims queued/failed → processing atomically; completed is not reprocessed without explicit retry. Retry resets a failed run to queued.

`analysis_enabled` and `daily_summary_enabled` default false. No per-message auto analysis. No nightly scheduler.

## Admin UI

Telegram Group page: Analysis section (Analyze today / yesterday / last 7 days / custom range) and tabs Summary, Decisions, Tasks, Events/Facts, Analysis Runs. Owner-only (`group_analysis` + existing owner middleware). Retry on failed runs. Capability `group_analysis` already on Owner.

Also in this commit (M11 follow-up): groups with `status=left` go to Archive; main list excludes them; clickable group/project rows; membership sync command restores `left` → `connected` on inbound.

## Project integration

`get_project_context` returns bounded ACTIVE group knowledge for attached groups (`config/projects.php`: `max_group_summaries=3`, `max_group_knowledge=12`): latest summaries + decisions/tasks/events. Compact group titles remain. No raw group dump. This is a tool result, not personal memory and not default conversation context.

`ConversationContextBuilder` and `PersonalMemoryRetriever` do not include group knowledge. Dedicated Group Search tool is M15 and was not added.

## Tests

`tests/Feature/GroupAnalysisTest.php` plus existing group/project/memory/DM/reminder suites. Fake Owner Analysis AI; no live Gemini/OpenAI calls. Temporary groups use reserved test chat ids `-91…` and are deleted in `finally`.

Coverage includes schema, owner start / user denied, timezone + DST, empty range skip AI, single-chunk persist of all four types, chunk/reduce, foreign/malformed reject, no personal-memory bleed, context builder exclusion, overlapping dedupe, supersede, retry without duplicate, run idempotency, project context derived-only, no auto analysis on inbound, task ≠ reminder.

`php artisan test`: 140 tests, 139 passed, 1 skipped (pre-existing owner Telegram identity skip).

## Build

`npm run build` succeeded (Vite). `public/build` is gitignored; assets built on this host.

## Production counts (after tests; no secrets)

| Table | Count |
| --- | --- |
| telegram_groups | 4 |
| telegram_group_participants | 6 |
| group conversations | 4 |
| group messages | 16 |
| telegram_group_knowledge | 0 |
| telegram_group_knowledge_sources | 0 |
| telegram_group_knowledge_revisions | 0 |
| telegram_group_analysis_runs | 0 |
| personal memories | 2 |
| projects | 2 |
| project_groups | 0 |

Raw group history preserved. Test knowledge rows were cleaned. No real group analysis was run.

## Worker status

- User systemd `jarvis-queue.service`: **active**, `queue:work database --queue=analysis,memory,default --timeout=180`
- Telegram worker: crontab `flock` `--queue=telegram` — present
- Reminder scheduler: `php artisan schedule:run` every minute; `schedule:list` shows `jarvis:reminders:dispatch` every minute
- `php artisan queue:failed`: none

## Manual smoke status

Cursor did **not** run live Admin smoke or `jarvis:groups:analyze` on the real test group. Owner should: seed 8–15 messages, Admin → Telegram Groups → Analyze today, confirm queued then completed Summary/Decision/Task/Event with sources, confirm no new personal memory, then (if the group is attached to the JARVIS project) ask in owner DM «Что нового по проекту JARVIS?» so `get_project_context` can read bounded derived knowledge.

## Known issues

- Auto daily/nightly group analysis is intentionally off.
- `get_project_context` only sees group knowledge after an Admin/CLI analysis run and a project↔group attach.
- Owner DM has no dedicated group-search tool yet (M15).
- Media is stored as compact placeholders; no vision/audio understanding.
- Edited-message analysis uses current body only; previous edit revisions are not analysed.
- Group task assignee is display text only; no mapping to Jarvis users.

## Next milestone

Milestone 15 — Group Knowledge Search: explicit owner DM tool for group-derived knowledge / on-demand analysis, still without auto-mixing groups into a normal personal greeting.
