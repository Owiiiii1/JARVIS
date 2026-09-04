# Cursor work report — Group Knowledge Search (Milestone 15)

Date: 2026-09-04  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `348fd33` (`feat: add Telegram group analysis`) |
| Commit message | `feat: add owner group knowledge search` |
| Working tree before start | clean on `origin/main` |

## Backup / migrations

No schema change. No new tables. No backup required. Existing group analysis tables reused.

`php artisan migrate --force` was not needed. Tool execution logs remain M16 and were not added.

## Tool definition

`search_group_knowledge` (`App\Services\Tools\SearchGroupKnowledgeTool`).

Arguments accepted:

- `query` (required)
- `group`, `project`, `range` (`today` / `yesterday` / `last_7_days` / `custom`)
- `date_from`, `date_to` (`Y-m-d`)
- `types` (`summary`, `decision`, `task`, `event_fact`)
- `include_raw_if_needed`
- `limit` (capped by config)

Rejected as owner-scope: `user_id`, `telegram_group_id`, message ids, raw SQL.

Description tells the model to use this for Telegram groups / group decisions / tasks / events / “what someone said”, and not for personal history, general project context, or reminders.

## Capability filtering

Owner + capability `group_analysis` only. Role is not hardcoded to an owner id.

Normal user:

- tool is absent from `ToolRegistry::definitionsFor`
- explicit forged `execute` returns `tool_not_available`

Owner registry after M15:

- `create_reminder`
- `search_conversation_history`
- `get_project_context`
- `search_group_knowledge`

User registry unchanged: reminder + history search.

Telegram DM and Web Cabinet share `ConversationTurnService` / the same tool runtime.

## GroupKnowledgeSearchService

Channel-neutral. Input is `GroupSearchRequest` + owner `User`. No Nutgram types.

Pipeline per resolved group:

1. own local range via `GroupTimeRangeService`
2. coverage (`GroupAnalysisCoverageService`)
3. ACTIVE derived knowledge first
4. bounded raw fallback if derived is insufficient or `include_raw_if_needed`
5. optional queue of existing M14 `GroupAnalysisRunService` (no wait)

Empty honest result: `success=true` and `No matching group knowledge/raw messages in requested scope.`

Malformed dates: `needs_clarification`. No guessed range.

## Group / project resolution

`GroupResolver` on Owner Space `telegram_groups`:

1. exact title
2. normalized title
3. bounded fuzzy candidate match

Ambiguous group or project returns candidates. No `telegram_group_id` from the model.

If `group` is omitted: connected/active groups first. Archived/left participate when the group is named or the query is historical (`include_archived_by_default` is false).

If `project` is set: `ProjectContextService` resolve + `project_groups` only. Unrelated groups are not searched.

## Range / timezone

`today` / `yesterday` / `last_7_days` / custom `Y-m-d` are computed **per group** in that group's IANA timezone (owner timezone fallback, same as M14). One UTC “today” is never applied to all groups. DST is Carbon/IANA. Model timezone offsets are ignored.

## Derived-first strategy

Priority:

1. ACTIVE `telegram_group_knowledge` matching tokens / type / range
2. summaries, then decisions / tasks / events
3. bounded raw only when derived is insufficient

Superseded / disputed / obsolete are omitted from current truth. Default types when empty: summary + decision + task + event_fact.

Task rows stay compact (`content`, assignee text, local due, status, source). No Reminder is created.

## Raw fallback

Used for questions derived knowledge did not store (participant quotes, specific phrasing).

- token match on content
- separate sender match: `sender_name`, `sender_username`, `TelegramGroupParticipant.display_name`
- no numeric Telegram id search
- no map to Jarvis User
- no full archive dump
- total and per-group caps in config

## Coverage / staleness

`GroupAnalysisCoverageService` for group + range:

- raw message count
- completed run covering the range
- derived item count
- queued/processing
- stale if new messages arrived after `completed_at` (threshold `stale_after_new_messages`)

`analysis_status`: `available` | `partial` | `missing` | `queued`.

Stale result may include bounded raw delta plus optional refresh queue.

## Queue-on-missing

If raw exists and analysis is missing or stale, the tool may call existing M14 `GroupAnalysisRunService::queue()`. Idempotency reuses queued/processing runs for the same group+range+mode. The tool returns immediately with currently available data and `analysis_status=queued`. No poll. No background user notification.

## Config limits

`config/group_search.php`:

- `max_groups`
- `max_knowledge_per_group`
- `max_raw_snippets_per_group`
- `max_total_raw_snippets`
- `max_source_snippets`
- `max_query_tokens`
- `stale_after_new_messages`
- `queue_missing_analysis`
- `default_types`

## Logging

Safe fields only: tool name, internal user id, groups searched, knowledge count, raw snippet count, queued analysis count, duration. No full raw text, no Telegram numeric ids, no tokens.

Tool result provenance is compact: group title, local timestamp/range, sender display name, source snippets. Numeric Telegram ids are not required in the AI-visible payload.

## Tests

`tests/Feature/GroupKnowledgeSearchTest.php`. Fake AI/provider. No live Telegram/Gemini. Temporary rows cleaned exactly.

Covered:

- owner receives tool; normal user does not
- forged user execution denied
- exact / fuzzy / ambiguous group resolution
- project filter uses attached groups only
- today per group timezone + DST
- derived first; type filters; ACTIVE only; superseded/disputed omitted
- participant name search; bounded raw; foreign group excluded; multi-group total bound
- provenance snippets; empty honest; malformed dates need clarification
- missing analysis queues M14; completed reused; stale after new messages; no wait; no duplicate run
- no personal memory / no default ContextBuilder group context / PersonalMemoryRetriever unchanged
- group task does not create reminder
- `create_reminder`, `search_conversation_history`, `get_project_context` still work
- multi-tool loop can call group search then another tool
- Web Cabinet path uses the same capability/runtime

Full suite: 149 tests, 148 passed, 1 skipped.

## Production counts

| Table | Count after M15 | Change |
| --- | --- | --- |
| telegram_groups | 4 | unchanged |
| telegram_group_knowledge | 0 | unchanged |
| telegram_group_analysis_runs | 0 | unchanged |
| memories | 2 | unchanged |
| projects | 2 | unchanged |
| users | 1 | unchanged |

No automatic analysis of production history. No live billable group analysis was started.

## Worker status

- `queue:work database --queue=analysis,memory,default` — active
- Telegram worker (`--queue=telegram`) — present (cron + flock)
- Reminder scheduler — `jarvis:reminders:dispatch` in `schedule:list`; host cron runs `php artisan schedule:run` every minute
- `php artisan queue:failed` — no failed jobs

## Manual smoke

Manual live smoke deferred by Owner.

Automated coverage is in `GroupKnowledgeSearchTest`. This report does not claim a live Telegram/Gemini group-search turn was executed.

## Known issues

- Cross-group default still skips archived/left unless the group is named or the query is historical.
- Queued analysis does not notify the owner when the job finishes; the user must ask again (proactive alerts are later).
- Search is relational (tokens / LIKE / structured fields). No Vector DB.
- M14 live smoke remains deferred; production `telegram_group_knowledge` is still empty, so live answers will lean on raw fallback until analysis is run.

## Next milestone

Milestone 16 — Integration Framework (`integration_accounts`, `tool_execution_logs`, confirmation policy skeleton, Integrations admin list).
