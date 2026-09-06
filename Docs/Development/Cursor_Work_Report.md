# Core Reliability Cleanup

## Starting HEAD

`3592da6` (`feat: add ElevenLabs realtime voice beta`). Working tree was clean. `HEAD` == `origin/main`.

Work ran on that main. No migration. Production database `jarvis` was inspected with read-only aggregates only. No mass retry, no prune, no truncate, no `migrate:fresh`, no live Gemini / ElevenLabs / Telegram / Google / GitHub calls.

## Production read-only failure inventory

Read-only counts at implementation time (no payloads, no transcripts):

| Surface | Domain failed | Laravel `failed_jobs` | Stuck `processing` > 15m | Pending `jobs` |
| --- | --- | --- | --- | --- |
| Memory (`AnalyzeConversationTurnJob` / `UpdateConversationSummaryJob`, queue `memory`) | 34 runs (87 completed) | 34 | 0 | 0 |
| Group analysis (`AnalyzeTelegramGroupRangeJob`, queue `analysis`) | 4 runs (3 completed) | 0 | 0 | 0 |
| Attachment summary (`SummarizeMessageAttachmentJob`) | 1 `summary_status=failed` (not purged) | 0 | — | 0 |
| Stored files | 0 failed (1 ready) | 0 | — | 0 |

`failed_jobs` clusters (exception class only):

- 13× `AnalyzeConversationTurnJob` / `AiSafetyException` (2026-09-05)
- 20× `UpdateConversationSummaryJob` / `AiSafetyException` (2026-09-05)
- 1× `AnalyzeConversationTurnJob` / `AiProviderException` (empty assistant response; 2026-09-05)

Domain `last_error` prefixes were bounded/sanitized: safety-policy text (33 memory runs) and empty-assistant text (1 memory + 4 group runs). No private message bodies were read.

## Failure clusters

| Cluster | Count | Category now | Retryable? | Decision |
| --- | --- | --- | --- | --- |
| Memory + summary safety blocks | 33 domain / 33 `failed_jobs` | `provider_safety` | no | Historical / still possible on the same content. This commit stops retrying them. Not auto-retried. |
| Memory empty provider response | 1 domain / 1 `failed_jobs` | `malformed_provider_response` | bounded yes | Historical; still possible as `AiEmptyResponseException`. Eligible later if Owner asks. |
| Group empty provider response | 4 domain / 0 `failed_jobs` | `malformed_provider_response` | bounded yes | Historical; job used to swallow the exception so Laravel recorded success. Eligible later if Owner asks. |
| Attachment summary failed | 1 | unknown (no category metadata yet) | no (command skips unknown) | Historical; needs Owner review, not mass retry. |
| Stuck processing rows | 0 | — | — | None at inventory time. Recovery command still added. |

This commit does **not** claim all historical failures are fixed. They are classified. Owner decides prune/retry.

## Memory pipeline

`AnalyzeConversationTurnJob` / `UpdateConversationSummaryJob`:

- Completed runs still skip (idempotent).
- Missing/deleted messages: existing run → `failed` + `missing_source`, no retry, no insert against a missing conversation FK.
- Ownership / non-personal: terminal `ownership` / `stale_source`.
- Transient provider errors: keep `processing`, rethrow, `$backoff = [30, 90, 180]`.
- Permanent (auth, safety, structured-output parse): domain `failed` with category only, job returns (no extra `failed_jobs` spam).
- `failed()` marks a still-processing run failed with a sanitized category.
- `last_error` stores the category value, not the raw exception message. Metadata holds `error_category`, `error_code`, `error_class`, `retryable`, `failed_at`.
- `MemoryWriter` still create-or-reinforces by normalized key; retry of a completed run does not duplicate memories.

## Group analysis pipeline

`AnalyzeTelegramGroupRangeJob` no longer catch-and-succeed.

- Missing group → `missing_source` (terminal).
- Left/archived group → `stale_source` (terminal). Raw group archive is not deleted.
- Empty range still completes with `no_data` (no LLM).
- Parse/`GroupAnalysisException` → terminal malformed, no retry loop.
- Transient provider errors rethrow with backoff; `failed()` updates the domain row.
- Completed runs still skip; knowledge writer still reinforce/supersede.
- Tries aligned to shared policy (3) with job timeout **170s** (under worker `--timeout=180`, `retry_after=300`).

## Attachment summary pipeline

`SummarizeMessageAttachmentJob`:

- Ready → skip.
- Purged / non-image → `NotRequired` + `stale_source` (not a system failure; not a `purge_failure_count` bump).
- Expired ephemeral with missing bytes → `stale_source` / `NotRequired`.
- Missing message/user → `Failed` + `missing_source`.
- Vision/config missing → terminal `provider_auth`.
- Empty vision text → bounded retry (`empty_provider_response`).
- `failed()` no longer increments `purge_failure_count`.
- Job timeout 120s vs Gemini vision HTTP 90s vs worker 180s.

## Failure classification

Shared `AsyncFailureClassifier` + `AsyncFailureCategory` used by Memory, group analysis, attachments, and stored-file job failure.

Categories: `provider_auth`, `provider_rate_limit`, `provider_quota`, `provider_timeout`, `provider_unavailable`, `provider_safety` (observed production class), `network`, `malformed_provider_response`, `validation`, `missing_source`, `stale_source`, `ownership`, `serialization`, `database`, `code_bug`, `unknown`.

Historical `last_error` prose is classified from bounded prefixes when metadata is absent.

## Retry policy

| Kind | Examples | Behavior |
| --- | --- | --- |
| Transient | timeout, network, 429, temporary 5xx, empty provider response | bounded tries + exponential backoff |
| Permanent | auth/config, safety, validation, missing/stale source, ownership, structured parse | no repeated retry |
| Code bug / unknown | `TypeError`, unclassified | fail once, visible category, no loop |

## Idempotency

Unchanged durable writers: memory create-or-reinforce; group knowledge reinforce/supersede; attachment skip if `Ready`. Reliability jobs do not send Telegram/Gmail/GitHub. Retry commands dispatch only eligible transient domain rows.

## Stale source handling

Deleted messages, left groups, purged/expired attachments: terminal `missing_source` / `stale_source` / `NotRequired`. Enums were not migrated.

Chat delete still cascades `memory_analysis_runs` via FK. A queued job for a deleted conversation returns without inserting an orphan run.

## Stuck run recovery

`jarvis:reliability:recover-stale` (scheduled every 15 minutes). Default threshold 30 minutes (`config/reliability.php`). Fresh `processing` rows are not marked stuck. Dry-run: `--dry-run`.

## Operational commands

Dry-run unless `--execute`. `--limit`, `--hours`, `--category`. Only retryable/transient (plus `stale_running`). **Not run against production in this work.**

- `jarvis:reliability:report`
- `jarvis:memory:retry-failed`
- `jarvis:groups:retry-failed`
- `jarvis:attachments:retry-failed`
- `jarvis:reliability:recover-stale`

Do not use `queue:retry all`.

## Retention / cleanup

Documented: `queue:prune-failed --hours=` using `config/reliability.php` `failed_jobs_retention_days` (14). Domain failure history is kept. **Prune was not executed.**

## Observability

`jarvis:reliability:report` prints counts by subsystem, last `failed_jobs` time (job class basename only), category/retryable, oldest processing, pending/reserved queue rows. No payload, prompt, transcript, or provider body.

## Automated tests

Isolated PHPUnit with fakes and `jarvis-test-*@invalid.local` users. No live providers.

Covered: transient Memory retryable; permanent auth terminal; `failed()` updates run; retry idempotent; missing deleted messages terminal; left group stale; expired attachment skipped; 429 backoff; auth no retry; stuck-running threshold; retry dry-run; retry excludes safety; diagnostics omit payload/transcript; Memory path does not dispatch `ProcessTelegramUpdate`.

## Documentation synchronization

`IMPLEMENTATION_PLAN.md` statuses brought to current main. Deferred live validations collected in [Docs/DEFERRED_VALIDATION.md](../DEFERRED_VALIDATION.md). Worker/queue docs aligned (`analysis,memory,default`, `--timeout=180`).

## Deferred validation backlog

Owner postponed live campaigns. They do not block further development. See [Docs/DEFERRED_VALIDATION.md](../DEFERRED_VALIDATION.md). Not MANUAL PASS.

## Production safety

- SELECT/aggregates only on production `jarvis`
- No mass retry / prune / truncate
- No live provider calls
- Telegram / Gmail / GitHub write adapters untouched
- `last_error` going forward is a category code

## Remaining risks

- Group analysis with many chunks can still exceed 170s job timeout (chunk × Gemini HTTP 60s). Not raised blindly above worker timeout.
- Scheduler `queue:work --max-time=50 --timeout=180` is a fallback beside `jarvis-queue.service`; systemd remains the long-running worker (`analysis,memory,default`, timeout 180). Telegram queue stays on its separate crontab worker.
- Historical `failed_jobs` still contain old exception text until Owner prunes.
- One historical attachment failure has no category metadata; retry command will skip it as unknown.
- C.1 / C.2 / integrations live validation still deferred.

**Status.** Core Reliability: **IMPLEMENTED**. Historical failures: **CLASSIFIED**. Not all failures fixed.
