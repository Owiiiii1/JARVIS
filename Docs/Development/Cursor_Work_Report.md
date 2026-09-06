# Phase C.1 — Conversation Intelligence

## Starting HEAD

`8208423` (`feat: add confirmed workspace chat deletion`). `git status` was clean. `HEAD` == `origin/main`.

Work ran on that main. No migration. Production database `jarvis` was not truncated, refreshed, or mass-updated. No live Gemini / ElevenLabs / Gmail / Calendar / GitHub / Telegram calls.

## Existing conversation architecture

Unchanged engine: channel adapter → `ConversationTurnService` → `ConversationAiService::completeUserTurn` → `ConversationContextBuilder` → `ContextBudgetManager` → tool loop → persist.

Voice (`VoiceRuntimeService`) and Telegram DM already call that turn path. C.1 does not add a second Voice AI, a second memory engine, a new message store, or a hidden personality prompt.

## Working context model

`App\Services\ConversationIntelligence\WorkingContext` is a small derived DTO: topic mode, current/previous topic, recent entities, trusted recent tool refs, recent intent, pending clarification, last important object, active project if indicated, temporary style, incomplete-utterance flag.

`WorkingContextBuilder` builds it from the recent semantic tail, conversation summary, topics linked to this chat, compact `tool_execution_logs` metadata, and (when the text matches) an owned project name. It is not a standing blob and is not written to Memory.

## Topic continuity

`TopicContinuityDetector` labels **continue / subtopic / switch / return** from the current utterance plus known topic names. Return phrases (“вернёмся к YFS”) recover the named topic so later retrieval can use topics, the current summary, and `search_conversation_history` when a deeper raw detail is needed. No separate ML classifier service.

## Reference resolution

`ReferenceResolver` uses structured recent entities plus deictics (“это”, “она”, “туда”, “эта задача”, “предыдущий”, …), not regex-only NLP as the whole product. Read-only replies may use the unique best interpretation. Mutation tools must not receive a guessed id.

## Clarification policy

`ClarificationPolicy` asks only for write/destructive ambiguity, missing required fields, ambiguous external destinations, contradictions, or genuinely insufficient confidence. It does not clarify “tomorrow” or other obvious relative dates.

## Recent tool references

`ToolExecutionService::safeMetadata` now stores compact `task_id` / `reminder_id` / `project_id` / titles and a short `listed_tasks` list. `RecentToolReferenceReader` exposes the last few **successful** Core results for this conversation. Lifetime is the current turn plus `context_budget.working_memory_turns` (default 4). A topic switch marks them expired. They are not permanent memory.

`TaskToolResolver` / `CreateReminderTool` may bind a **unique trusted** recent task when the user refers to it with a pronoun (“Напомни про неё”). Several similar tasks (“закрой отчёт”) stay `ambiguous`. Invented ids on a pronoun turn are rejected.

## Working vs permanent memory

Working context is conversation-scoped and may disappear as summaries evolve. Durable user facts still go through the Memory Engine only when they qualify as memories. Choosing between two products in the current chat is working context, not an automatic memory write.

## Personality consistency

`PersonalityPresentationBuilder` wraps `AssistantProfileService::identityContext` for every channel. Voice spoken-style remains a presentation hint. Temporary style (“отвечай коротко”) is injected for this conversation only and is not written to `user_assistant_profiles`.

## Conversational initiative

Policy text: default **answer and stop**. At most one high-value suggestion tied to the current turn. No “Хочешь, я…” on every reply. No automatic external write from a suggestion. Scheduler/proactive productivity policy is unchanged and not duplicated.

## Turn supersession

The PHP turn is not cancelled (no distributed cancellation). Web Workspace (and legacy cabinet chat) keep the composer usable while thinking, abort the previous **fetch wait**, and ignore stale JSON via a generation counter. Persisted user messages remain. Tool writes that already ran are not rolled back. After reload, both assistant rows may exist in history; the UI will not paint the old payload over the newer turn.

## Voice / Telegram reuse

Same `ConversationTurnService` / context builder / working context / tools. No Voice-specific or Telegram-specific intelligence layer.

## Context budget

New slices in `config/context_budget.php`: `working_context`, `recent_entities`, `recent_tool_references`, `conversational_policy`, `working_memory_turns`. Trim order still drops cross-chat / memories / projects before working context, then summary / general / identity / policy, and never drops platform or the current user turn. `ContextBudgetManager` remains authority.

## Failure fallback

`WorkingContextBuilder::buildSafe` and an extra try/catch in `ConversationContextBuilder` return `WorkingContext::unavailable()` on failure. Chat still assembles the previous slices. Personality builder falls back to `identityContext`.

## Tests

Isolated PHPUnit (fakes / temp `jarvis-test-*` users / Mockery). No live providers. No `RefreshDatabase` / `migrate:fresh`.

- continuation pronoun → unique trusted task
- two similar tasks + “отчёт” → ambiguous, no mutation
- YFS / incomplete “а если его завтра?” resolve when unique
- return-to-topic detection
- temporary style does not rewrite the profile
- expired/switch tool refs are not reused
- working-context exception falls back to the normal engine
- personality presentation is channel-agnostic

Frontend supersession has no JS test runner in this repo; behavior is in `PersonalWorkspace.jsx` generation/AbortController.

## Build/static checks

`php -l` on touched PHP, Pint `--dirty`, `composer validate`, `npm run build`, `git diff --check`. Migrate status unchanged (no new migration).

## Production safety

No destructive DB operations. Additive use of existing `tool_execution_logs.metadata` only. Temporary test users cleaned via `CleansTemporaryJarvisRecords`.

## Known limitations

- Server-side in-flight LLM/tool cancellation is not implemented (C.2 / later).
- Topic mode is heuristic, not a classifier service.
- Trusted ids come from compact tool-log metadata, not model imagination; a model can still pass an explicit owned `task_id` on a non-pronoun turn (existing behavior).
- STT transcripts are stored as-is; interpretation is in the context layer only.
- C.1 is **IMPLEMENTED / NOT VALIDATED**. C.2 is still **PLANNED**.

## Owner manual checklist

A. “Создай задачу купить фильтр для станка”, then “Напомни про неё завтра утром” → reminder linked to that Task.

B. Discuss YFS, switch topic, then “вернёмся к YFS, что там с голосом?” → topic recovered.

C. Two tasks about a report, then “закрой задачу про отчёт” → if ambiguous, Jarvis asks; no arbitrary close.

D. “отвечай сейчас максимально коротко” for several turns → short style now; profile not permanently rewritten.

E. Voice: “а это?” / “а если завтра?” / “и потом?” → context holds when resolvable.

F. Send a new message while thinking → old response must not visually replace the new turn.

G. Jarvis must not start every answer with a follow-up suggestion.
