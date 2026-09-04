# Context Budget Manager

**Status.** IMPLEMENTED / NOT VALIDATED (M22.3, 2026-09-04). Automated tests and live AI calls are deferred by Owner.

One LLM request has a bounded prompt, independent of how large the database becomes. A million messages, a hundred thousand Storage files, Gmail, GitHub, Telegram groups, and web research must not dump themselves into a single request.

See [WEB_RESEARCH.md](WEB_RESEARCH.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [STORAGE.md](STORAGE.md).

---

## Invariant

After the conversation-summary threshold, adding more raw messages to MySQL must not materially grow the normal per-turn prompt.

1k messages vs 1M messages: the base request stays roughly the same bounded size. Retrieval complexity and indexes may grow. Raw history is never sent whole. Compaction never deletes raw messages.

---

## Components

| Piece | Role |
| --- | --- |
| `config/context_budget.php` | Named token slices and tool-round cap |
| `config/ai_model_context.php` | Per-provider / per-model context windows + output reserve |
| `AiModelContextPolicy` | Resolves max context and input budget; unknown model → conservative default (32k / 2k) |
| `TokenEstimator` | Provider-neutral overestimate (Unicode chars/words + overhead). Prefer overestimating. |
| `ContextBudgetManager` | Assembles and trims one request until estimated input ≤ input budget |
| `ToolResultBudgetManager` | Second safety layer on every ToolResult (web / Gmail / GitHub / Storage / group) |
| `TurnBudgetTracker` | Per-turn web call caps + tool-result token counters |
| `ContextDiagnosticsLogger` | Safe metrics only (no prompt text) |

`ConversationContextBuilder` gathers slices. It does not append unlimited strings on its own.

Hard guarantee: before each provider call, `estimated_input_tokens <= input_budget`. The builder/manager trim until that is true. Do not rely on provider HTTP 400 “context too long”.

Input budget = model max context − reserved output − safety margin.

---

## Priority (highest first)

1. Platform / system instructions (never dropped)
2. Current user turn (never dropped; storage excerpt on that turn may shrink)
3. Tool / confirmation-critical application events
4. User General Prompt
5. Recent current conversation (token-bounded, newest backwards, complete message boundaries)
6. Current conversation summary
7. Relevant personal memories
8. Cross-chat summaries of the same user
9. Projects / attachments (projects are still tool-retrieved, not auto-injected)
10. Optional tool context already in the loop

Never truncate away system or the current user turn just to keep old memories.

---

## Source rules

| Source | How it enters one request |
| --- | --- |
| Recent current chat | Raw window, token-bounded, newest first. Message-count cap is only a query bound. |
| Older current chat | `conversation_summaries` (incremental, coverage `from_message_id` / `to_message_id`) |
| Other chats | Summaries first. Raw only via `search_conversation_history`. |
| Personal memory | Retriever candidates, then budget cap |
| Screenshots | Bounded derived summary text. Never historical image bytes (M22.2). |
| Persistent Storage | Never auto-inject whole files. Current attached file: bounded metadata + small excerpt. Historical: tools. Tool results still pass the global tool budget. |
| Web / Gmail / GitHub / groups | Tool results only, for that turn. Not standing context. |

---

## ToolResult budget

Local per-tool bounds remain. `ToolResultBudgetManager` is a second layer.

Shared token budget for all tool responses in one turn. Trim **content/excerpts/lists** first. Preserve success/error, ids, pagination/`truncated`, key metadata. Never emit invalid object shape.

If the remaining budget is too small: compact `tool_context_budget_exceeded` rather than a huge payload.

---

## Conversation compaction

Existing `UpdateConversationSummaryJob` / `ConversationSummaryService`.

Refresh when unsummarized semantic messages exceed `memory.summary_message_threshold` **or** estimated tokens exceed `context_budget.summary_refresh_tokens`.

Incremental: previous summary + messages after `to_message_id`. Unsummarized load is capped (`unsummarized_message_cap`). Summary text itself is capped (`summary_max_chars`); oversized previous summaries are recompressed.

Raw messages are never deleted. Summary is derived. Coverage boundary already exists on `conversation_summaries`.

---

## Diagnostics

Log channel `context budget` per AI request:

- user id, conversation id
- model / configuration role
- estimated_input_tokens, output_reserve, input_budget
- counts/tokens per source
- trimmed counts
- utilization percent
- overflow_prevented

No actual texts. Compact copy on assistant `metadata.ai.context`. Admin AI settings remain credential/config UI; no new admin subsystem.

---

## Scale notes

Context queries used for prompt assembly use LIMIT / exists checks. They must not hydrate all messages of a conversation.

Persistent files stay on local private disk. Future object storage is a later threshold, not this milestone.

Web search snippets and fetched pages consume web/tool budget for the current loop only. They are not persisted as context or memory.
