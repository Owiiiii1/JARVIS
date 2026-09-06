# Phase E.1 — Knowledge Layer

## Starting HEAD

`3a029a00c430fd50a51c9a004656fe4d696aa033` (`fix: harden async core reliability`). Working tree was clean. `HEAD` == `origin/main`.

No watchers. No live extraction. No historical graph backfill. No Google/Gmail/GitHub polling.

## Existing Memory / Projects architecture

Memory Engine remains independent: `memories` + sources + revisions, Analysis AI turn/summary jobs, `MemoryWriter`, personal retrieval into context.

Projects remain the canonical work container (`projects` table, Owner capability). Chat uses `get_project_context`; Projects are not auto-injected.

C.1 `WorkingContext` already tracks recent entities and an active project name. Context Budget already trims slices so the current turn survives.

## Knowledge data model

Additive MySQL tables: `knowledge_entities`, `knowledge_entity_aliases`, `knowledge_relationships`, `knowledge_events`, `knowledge_event_entities`, `knowledge_entity_sources`, `knowledge_analysis_runs`.

No Neo4j. No second Memory schema rewrite.

## Entities

Types: person, project, organization, product, place, topic, system, file, custom.

A project-typed entity may store `project_id` as a semantic index. Project name/status stay on `projects`.

## People

Semantic people intelligence: name, aliases, sourced summary, relationships, provenance. Not a CRM. Contact details are not invented. Sensitive profiling attributes are not modeled.

## Aliases / merging

Aliases live in `knowledge_entity_aliases`. Auto-link only for the same type + normalized name, a stored alias, the same Project, or an explicit external ref, at high confidence. `YFS` vs `Young Fashion Show` stay separate until aliased. No destructive merge tool.

## Relationships

Controlled types (`works_on`, `works_for`, `uses`, …). Unique per user + pair + type. Updates upsert. Ended facts deactivate (`status`, `valid_to`, `superseded_at`). History stays on the timeline.

## Timeline

`knowledge_events` with stable `source_fingerprint`. Pivot to related entities. Event types cover Core actions and compact integration facts. Watcher conditions can attach later; execution is not implemented.

## Provenance

Every automatic fact attaches `knowledge_entity_sources` (conversation/message/memory/project/task/file/integration ref + fingerprint). Manual notes are explicit `manual` sources.

## Memory integration

New/reinforced Memory and completed conversation summaries may queue `ExtractKnowledgeFromSourceJob` (disabled in phpunit). Memory rows are never deleted because Knowledge exists. “Запомни…” may write Memory (existing engine) and Knowledge (explicit tools) without a second confirmation modal for core writes.

## Project integration

`ProjectService::create` / `archive` deterministically upsert a project entity and a timeline event. Project CRUD does not move into knowledge tables.

## Incremental ingestion

Central writer: `KnowledgeIngestionService`. Deterministic hooks: Task create/complete, Reminder create, Project create/archive, StoredFile ready. Optional compact ingest from Gmail/Calendar/GitHub **tool results on a user turn**. No production-wide scan.

`jarvis:knowledge:backfill` is dry-run by default and requires `--user`. It was not run live.

## Deterministic vs AI extraction

Structured Core data → no LLM. Unstructured Memory/summary text → Analysis AI, strict JSON, explicit vs inference. Low confidence does not auto-create relations.

## Context retrieval

`KnowledgeRetriever::contextBlock` uses C.1 active project / recent labels / current turn. At most 3 high-confidence entities, 5 relations and 5 events each.

## Context budget

New slice `knowledge_context` (default 500 tokens). Overflow drops knowledge after cross-chat summaries and **before** memories, so Knowledge never crowds out the current user turn.

## Knowledge tools

Read: `search_knowledge`, `get_entity`, `get_entity_timeline`, `get_entity_relationships`, `list_related_entities`.

Write: `remember_entity`, `link_entities`, `add_knowledge_note`.

Foreign ids → `not_found`. No merge/delete tools. Compact payloads; `entity_id` is kept under tool-result compaction.

## Workspace UI

Settings → Knowledge, next to Memory. Search, People, Projects, recent activity, entity detail. JSON under `/jarvis/knowledge` and `/chat/knowledge`. No graph canvas.

## Ownership / privacy

`user_id` is the graph. Regular users get capability `knowledge`. Owner has `*` but still only their own rows in Personal Workspace. No cross-user index. No secrets, tokens, or full email bodies in metadata.

## Source deletion behavior

Chat delete detaches Knowledge provenance (null conversation/message ids) the same way as Memory. Entities survive if other sources remain. Auto-derived rows with zero live sources become `orphan_candidate`. No FK failure. Chat-delete MANUAL PASS contract is preserved.

## Reliability

`ExtractKnowledgeFromSourceJob` uses `HandlesClassifiedAsyncFailure`. Stale/missing source is terminal. Transient provider errors retry. Safety/auth are terminal. `knowledge_analysis_runs` plus stale recovery / reliability report.

## Migrations

One additive migration `create_knowledge_layer_tables`. No Memory rewrite. No data backfill in the migration.

## Automated tests

Isolated PHPUnit coverage for create/idempotency/aliases/unsafe merge, relationship upsert/supersede, provenance, isolation, project authority, chat-delete detach/orphan, stale/transient/safety extraction, context bounds, compact search, foreign tool ids, no watchers. phpunit disables live extraction and tool-result ingest.

## Production safety

No live model calls, no historical extraction, no integration polling as part of this deploy. Queue reuses `memory` (configurable `KNOWLEDGE_QUEUE`).

## Deferred validation

Added to [DEFERRED_VALIDATION.md](../DEFERRED_VALIDATION.md): Phase E.1 Knowledge Layer — IMPLEMENTED / NOT VALIDATED. Owner is not asked to test now.

## Known limitations

No watchers. No CRM. No aggressive merge UI. No mass backfill. Integration facts only from in-turn tool results. Group Telegram knowledge stays on the group tables; it is not copied into the personal graph automatically.

## Next Phase E.2 foundation

Event types, provenance fingerprints, and per-user entities are attachable conditions for later watchers. E.2 is **not** implemented. Phase E is **not** marked complete.
