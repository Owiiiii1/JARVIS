# Agent Runtime Reliability

## Starting HEAD

`ac46f037e01a7370f486155dffa8227cefdb4cbd` (`feat: add recurring gmail monitoring`).

Branch `main`. No dependency changes.

## Pre-existing uncommitted fix

Working tree already had a local recovery patch that was **not** discarded:

- `AiFailureFallback`: `list|get|search|find|show|describe|read|fetch|compare` no longer counted as a successful write → «Готово.»
- `ConversationAiService`: after the hard tool-round cap, one no-tools pass was attempted
- `context_budget.provider_retries` + Gemini `protoStruct` empty-args object encoding
- matching tests in `AiRuntimeTest`, `AiFailureFallbackTest`, `RemindersTest`, `AiProviderChatClientTest`

That patch is absorbed and replaced by the official runtime below.

## Live failure class

Owner CNC Storage analysis: the model called `get_storage_file` / `search_storage_file_contents` / `read_storage_file_chunks` until `MAX_TOOL_ROUNDS`, never synthesized, then the user saw «Но при формировании ответа произошла техническая ошибка.» Follow-ups («повтори», «ты тут?», «эй») could re-enter the same unfinished read loop.

## Root cause

1. Tool-round cap terminated the turn without a mandatory no-tools answer phase.
2. Successful read tools were still eligible for a write-style fallback («Готово.» + technical-error suffix) when prefix matching failed.
3. Empty/capped turns had no partial-answer path.
4. A new user message still offered the full tool set, so the model could resume the previous Storage plan from history.

## Turn isolation

`TurnIsolation` + conversational policy: each user message is a new execution turn. Tool plans, retry state, and incomplete loops are request-scoped. Presence checks («эй», «ты тут?») disable tools for that turn. «Повтори предыдущий» asks to restate the previous semantic answer, not to replay a stale tool plan.

## Tool progress detection

`ToolLoopProgress` + `ToolCallFingerprint` record per-round tool name, normalized args (ownership fields ignored), semantic result fingerprint (file/query/chunk indexes, not bodies), success, and whether new information appeared.

## Repetition detection

Stops the tool phase after `context_budget.no_progress_tool_rounds` (default 2) consecutive no-progress rounds, or a 2–3 tool alternating cycle with no new fingerprints. Identical call fingerprints reuse the previous `ToolResult` and do **not** execute again (blocks duplicate external writes).

## Forced final synthesis

Official phase in `AgentToolLoop::synthesize()`: tools disabled, original request, compacted unique tool results, instruction “Do not call tools. Answer using information already collected.” Hard `max_tool_rounds` (default 8) also enters this phase instead of a fallback.

## Provider retry

`chatWithRetries` retries only classified retryable failures: timeout, 429, 5xx, empty/malformed-empty response, network. Attempt 2 uses compacted messages. Not retried: invalid credentials, config, safety, serialization, deterministic validation. Tools are never re-executed on a provider retry. Gemini empty `functionCall.args` is encoded as `{}` so protobuf list errors are not generated.

## Partial recovery

If some tools succeed and others fail, synthesis is instructed to answer from the successful set and mention the limitation in plain language. No provider exception names, tool IDs, or stack traces in the user text.

## Read/write classification

`ToolMeta::mutationKind()` → `ToolMutationKind`: `read`, `write_internal` (no provider), `write_external` (provider set), `destructive`. Runtime uses `isReadOnly()` / `isMutation()`. Fallback no longer classifies by name prefix when `ToolSemantics` can resolve the registry.

## Failure fallback changes

- Read-only success never returns «Готово.»
- Mutation success may still use a short completion line (reminder/watcher/profile/onboarding) **without** «при формировании ответа произошла техническая ошибка»
- Last-resort copy: «Сейчас не удалось сформировать ответ. Попробуй ещё раз.»
- Same string is `ConversationAiService::AI_FAILURE` / `AiFailureFallback::ANSWER_UNAVAILABLE`

## Logging

Bounded `Log::info('agent_turn', …)`: `turn_id`, `conversation_id`, `user_id`, `tool_round_count`, tool **names** + success/progress/reused flags, `repetition_detected`, `forced_final_synthesis`, `provider_retry_count`, `partial_recovery`, `final_outcome`. No email bodies, file contents, prompts, secrets, or tokens.

## Files changed

New: `AgentToolLoop`, `AgentTurnTrace`, `ToolLoopProgress`, `ToolCallFingerprint`, `ToolMutationKind`, `ToolSemantics`, `TurnIsolation`, `CountingFakeTool`, unit/feature reliability tests.

Updated: `ConversationAiService`, `AiFailureFallback`, `ToolMeta`, `ToolOperationClass`, `ClarificationPolicy`, `ConversationalPolicyPrompt`, `GeminiClient`, `AsyncFailureClassifier`, `config/context_budget.php`, docs, existing AI/reminder tests.

Unrelated dirty workspace files (OAuth UI, Workspace UX, watcher continuity, etc.) were left unstaged.

## Tests authored but NOT executed

- `tests/Feature/AgentRuntimeReliabilityTest.php` — A–I scenarios (multi-read answer, repetition exit, hard cap synthesis, partial, empty recovery, «эй» isolation, no duplicate write)
- `tests/Unit/Ai/ToolLoopProgressTest.php`
- `tests/Unit/Tools/ToolMetaTest.php`
- `tests/Unit/Conversations/TurnIsolationTest.php`
- updates: `AiFailureFallbackTest`, `AiRuntimeTest`, `RemindersTest`, `AiProviderChatClientTest`, `AsyncFailureClassifierTest`

DO NOT EXECUTE phpunit / `php artisan test` / Pest on this server.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty`, `composer validate`, `npm run build`, `git diff --check`. No scheduler, no live provider, no live tools.

## Owner validation plan

1. CNC Storage: ask Jarvis to analyze the file and compute axis drift. Expect a real or partial answer, not «техническая ошибка».
2. Then send «эй» / «ты тут?». Expect a short presence reply, not more Storage reads.
3. «Напомни мне завтра проверить почту» still creates a Reminder.
4. If a mutation (reminder/profile) succeeds and the speak-back fails, expect «Готово…» without a technical-error suffix.

## Production safety

No PHPUnit. No live AI/provider calls by Cursor. No live Storage/Gmail/Calendar/GitHub tool execution. No production user data edits. No emails sent. Gemini JSON encoding change is request-shape only.
