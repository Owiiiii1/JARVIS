# Phase E.3 — Cross-source Synthesis & Intelligence

## Starting HEAD

`bd10769e044327796db739b2187450afa1f67a0a` (`feat: add event-driven watchers`). Working tree was clean and equal to `origin/main` before this milestone.

## Existing domains

Jarvis already stored Memory, Knowledge entities/relations/events, People/Projects links, Tasks, Reminders, Watchers, conversation summaries, Gmail/Calendar/GitHub observations from tools/watchers, Notification Center, and B.2 Daily Brief / Weekly Review / proactive heuristics.

Those domains remain authoritative. E.3 does not replace them.

## Synthesis architecture

`CrossSourceSynthesisService` is a read-only pipeline:

scope → FactPack → dedupe → waiting / commitments / blockers / attention → rank → conflicts → optional Analysis AI narrative → bounded `SynthesisResult`.

Split services (not one 2000-line class): `SynthesisFactCollector`, `SynthesisDeduplicator`, `WaitingForResolver`, `CommitmentResolver`, `CommitmentLanguage`, `CommitmentLifecycle`, `ProjectAttentionResolver`, `SynthesisChangeAssembler`, `SynthesisRanker`, `SynthesisConflictDetector`, `SynthesisNarrativeService`, `SynthesisCache`, `SynthesisClock`.

Typed DTOs: `SynthesisScope`, `FactPack`, `SynthesisResult`, `SynthesisItem`, `SourceRef`. Closed `SynthesisType` vocabulary (nine types). Config: `config/synthesis.php`.

## Fact collection

Collector reads the user’s own Tasks, Reminders (via task project), Watchers/occurrences, Knowledge entities/events/relations, conversation summaries, and Owner Projects when the user has `projects`.

It never instantiates Gmail/Calendar/GitHub clients. Fresh live data stays behind existing tools when the user asks.

FactPack caps are config-driven (projects 5, people 10, events 30, tasks 30, waiting 20, commitments 20, …). Bounded titles/ids/timestamps only — no raw bodies.

## Deduplication

Deterministic. Prefer `fp:{source_fingerprint}`, then knowledge event id, then watcher occurrence link, then temporal + entity identity. The same GitHub commit indexed as a Knowledge event and a watcher occurrence is one change item.

## Project intelligence

`get_project_status` (capability `projects`) returns summary, recent changes, open work, blockers, waiting-for, people, upcoming, attention/risks as explicit labels (Blocked / Waiting external / Deadline risk / Active / No recent activity), freshness, sources.

`get_project_context` is unchanged. Project row still wins on name/status.

## People intelligence

`get_person_status` resolves a Knowledge person in the caller’s graph. Output: who, context, last activity (unknown if no indexed interaction — not `updated_at`), open loops, waiting for them / they may be waiting, related projects, recent changes. Foreign entity ids are `not_found`. No CRM scoring. Regular users never receive Owner project-derived slices.

## Waiting-for

Derived. No `waiting_items` table. Sources: one-shot watchers still waiting, open external-dependency tasks, `waiting_on` relations, explicit open commitments from others.

When the watcher completes, the item disappears on the next compute. Suggested follow-up timing is a recommendation only.

## Commitments

Explicit language only (`I'll send`, `обещаю`, `Marco обещал`). Vague phrases are not commitments. Stored as Knowledge events (`commitment_made` / `fulfilled` / `cancelled`) and relations (`waiting_on` / `committed_to`) with metadata actor/action/due/status. Completing a Task fulfills a commitment only when `metadata.task_id` matches. Extraction schema extended minimally; inference skipped.

## Blockers

Grounded: overdue prerequisite, waiting-for external, blocked watcher, unresolved project task, explicit blocked-by relation, approaching deadline with incomplete prerequisite. Task volume is not a blocker.

## Recent changes

Default 7-day window, user timezone. Combines Knowledge events, task/reminder/watcher/project/summary changes, already-indexed integration observations. Deduped as above.

## Attention ranking

Deterministic scores: overdue, deadline proximity (24h/48h), watcher-triggered, explicit blocker, waiting age, project relevance, user priority, recency. Analysis AI is optional narrative / grouping, not reorder-the-world.

Active project + open work + no activity beyond `inactivity_days` can be attention. Archived/dormant projects are not.

## Daily Brief integration

Existing `jarvis:briefs:dispatch` and Productivity opt-in clocks. Collector adds bounded waiting-for, project changes, top 3 attention items. No second Daily Brief system.

## Weekly Review integration

Same scheduler. Sections consume synthesis (moved / completed / stalled / waiting / commitments / next-week deadlines / follow-ups / watchers). Not an event dump.

## Proactive integration

`jarvis:proactive:dispatch` may emit closed types `follow_up`, `project_blocked`, `waiting_too_long`, `deadline_risk`, `stale_project`, `commitment_due` through existing `ProactivePolicy` (opt-in, 3/day, cooldown, quiet hours, dedupe keys). E.3 does not bypass B.2.

## AI narrative layer

`SynthesisNarrativeService` receives the FactPack, not databases. Compact summary / grouping / suggested next step. Conversation AI is not used for authoritative state.

## Deterministic fallback

If Analysis AI throws, structured facts still return. Briefs still render deterministic text. Tests cover the failure path.

## Freshness / conflict handling

Each result has `generated_at`, timezone, `data_freshness`, last-known external observation. Stale GitHub/Gmail/Calendar observations tell the model to use the live tool.

Authority: Task > Project > Reminder > Watcher > explicit live fetch > Knowledge > Memory. Unresolved conflicts surface “Есть противоречивые данные” plus source refs. Task-open beats stale Knowledge `task_completed` and still records the conflict as resolved-by-authority.

## Tools

`get_synthesis`, `get_project_status`, `get_person_status`, `list_waiting_for`, `list_commitments` (`mine` / `others` / `all`). Read-only. Compact JSON. Tool prompt maps natural questions (“что по YFS?”, “что я обещал?”) without auto-injecting full synthesis every turn.

## Workspace UI

Compact **Обзор** panel (Today / Needs attention / Waiting / Recent changes). Knowledge person/project cards show bounded synthesis. Routes: `GET /jarvis/synthesis`, `/synthesis/project/{project}`, `/synthesis/entity/{entity}` and `/chat` mirrors. Not a BI dashboard.

## Ownership / privacy

Per-user only. Regular users: own tasks/reminders/knowledge/watchers/conversations. Owner: plus Projects and integration-derived knowledge. No cross-user joins, no admin bypass in Personal Workspace, no secrets/tokens/full bodies.

## Context budget

New slice `synthesis_context` (~220 tokens) only when C.1 has an active project. Dropped first on overflow — before knowledge and memories. Current turn and Memory are never crowded out. Tools remain the primary retrieval path.

## Migrations

None. Waiting-for is derived. Commitments reuse Knowledge string enums. Cache is Laravel Cache (TTL 90s, per-user version bump on Task/Reminder/Knowledge/Watcher/Project changes). No historical narrative archive.

## Automated tests

`tests/Feature/CrossSourceSynthesisTest.php` (isolated/fakes) covers spec §68 in 11 tests: project combine + fingerprint dedupe; one-shot waiting resolve; overdue + external-dependency blockers; explicit vs vague commitment + fulfill only with task link; person own-graph / foreign denied / unknown activity; Owner project excluded for regular user; Task authority vs Knowledge + unresolved claim conflict; freshness/stale GitHub window/timezone/archived-not-stale + active-no-activity attention; briefs + proactive caps + AI fallback + cache bump + no auto watcher/mail; context budget clips synthesis first; HTTP scoped.

Related Knowledge / Watchers / ProductivityEngine tests still pass. No live providers.

## Production safety

No live Gmail/Calendar/GitHub from synthesis. No historical knowledge backfill. No live Analysis AI campaign. No production proactive notifications or watcher fires as “validation.” Tools cannot mutate external systems.

## Deferred validation

Owner continues to defer live campaigns. E.1, E.2, and E.3 are **IMPLEMENTED / NOT VALIDATED**. Recorded in [DEFERRED_VALIDATION.md](../DEFERRED_VALIDATION.md).

## Remaining product gaps

Meaningful Jarvis capabilities still missing **after E.3**, excluding deferred validation:

**A. Missing product functionality**

- Confirmed **external writes** from watchers (Gmail send / Calendar write / GitHub write still proposed-only; no silent or auto execute).
- A first-party **non-Web client** (Mobile) and the versioned Client API that would serve it.
- Deeper **people merge/alias** UX beyond E.1 high-confidence auto-link (still manual/alias, not a CRM).
- **Voice-native** presentation of synthesis (spoken brevity exists; no dedicated spoken overview flow).
- **Group-conversation** synthesis as a first-class scope (group knowledge remains tool/search, not E.3 FactPack).
- User-triggered **refresh** of a stale integration observation from the Overview UI (chat tools can already fetch live).

**B. Optional enhancements**

- Richer Overview layout / filters without becoming a BI dashboard.
- Watcher-suggestion UX (“поставить watcher на ответы Apple”) as a one-click confirm, still not auto-create.
- Broader commitment language packs; supersede/cancel from chat without a Knowledge write tool today.
- Cache warming / longer TTL for Owner-only heavy FactPacks.
- Telegram delivery of the compact Overview (briefs already exist).
- Semantic tie-break ranking when deterministic scores collide (Analysis AI is already optional narrative).

**C. Deferred clients**

- Mobile app and versioned Client API — not current work; built only if Mobile starts.
- Desktop / Tauri — CANCELLED (ADR-235).

**D. Deferred validation**

- Live E.1 Knowledge (extraction quality, Settings UI, chat-delete provenance).
- Live E.2 Watchers against real Gmail/Calendar/GitHub.
- Live E.3 synthesis questions on real Owner projects.
- Live B.2 Tasks / Notification Center / briefs / proactive (including new suggestion types).
- Google Calendar / Gmail / GitHub campaigns, Telegram Voice Input, C.1/C.2, onboarding E2E, A/B isolation, Tavily, destructive Storage, reliability prune.

Phase E is **not** complete. There is **no Phase E.4** invented to continue numbering.

## Optional enhancements

See B above. None of these are required to call E.3 implemented in code.

## Deferred clients

See C above.

## Next recommendation

Do **not** start an E.4. Pick from:

1. **Owner live validation** of E.1–E.3 plus B.2 (highest product-risk reduction; no new code required to “finish Phase E” as a claim).
2. **Confirmed external actions** (the remaining E.2 automation gap: execute a proposed Gmail/Calendar/GitHub write after explicit confirmation).
3. **Mobile / Client API** only if that client is actually starting.

Until Owner chooses, treat E.1 / E.2 / E.3 as IMPLEMENTED / NOT VALIDATED and keep shipping from the gap list, not from a new letter.
