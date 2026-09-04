# Cursor Work Report — M22.3 Web Research + Context Budget Manager

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git  
**Branch:** `main`

---

## Before

Origin/main HEAD before this work:

`342dd6d92210094acf45eaa5ce3e6599f5a3591e`  
`feat: add Jarvis persistent storage and ephemeral media`

M22 / M22.1 / M22.2 were already implemented (not live-validated). Conversation Engine was not rewritten.

---

## What shipped

### Web Research (Tool Layer)

- Provider-neutral `WebSearchProvider` + `WebSearchManager`.
- Initial provider: **Tavily** (`TavilyWebSearchProvider`). Unconfigured / unknown provider → `NullWebSearchProvider`.
- Env **names** in `.env.example` only: `WEB_SEARCH_PROVIDER`, `WEB_SEARCH_API_KEY`. No real keys written.
- Config: `config/web_research.php`.
- Tools: `search_web`, `fetch_web_page` (read, capability `web_research`, owner via `*`). Regular users do not receive them.
- Tools call the manager / `WebPageFetchService`. No ad-hoc HTTP in controllers or models.
- `search_web` returns compact hits + `WebSourceReference`. It does **not** auto-fetch pages.
- `fetch_web_page` returns bounded readable text (HTML/text/JSON). No JS, no browser, no binary/PDF.

### URL / SSRF

- `WebUrlGuard`: http/https only; deny localhost, loopback, RFC1918, link-local, metadata hosts, internal hostnames, credentials, numeric hosts, non-http schemes.
- DNS resolve before fetch; every redirect target revalidated.

### Prompt injection / authorization

- Platform untrusted-data rule covers web pages.
- Web content cannot grant Gmail/GitHub/Storage/tool rights. Confirmation policy unchanged.
- Search query must not include secrets; none are sent as extra headers beyond the configured provider key on the Tavily client.

### Per-turn web caps

Server-side on `TurnBudgetTracker` (model cannot override):

- max searches
- max fetches
- max total web chars

Overflow → `web_research_budget_exceeded`.

### Sources

- DTO `WebSourceReference`.
- Model instructed to add a real **Sources** section from tool URLs only.
- Optional assistant `metadata.web_sources` (ids/titles/urls, no page bodies).

Web facts do not auto-enter personal memory. Fetched pages are not stored. No web content tables.

### Context Budget Manager

- `config/context_budget.php`, `config/ai_model_context.php`
- `ContextBudgetManager`, `AiModelContextPolicy`, `TokenEstimator` (conservative overestimate)
- Recent history token-bounded, newest backwards, complete messages
- Current conversation summary, memories, cross-chat, projects, storage excerpt, screenshot summaries all have named budgets
- System/platform and current user turn are preserved
- Hard check before each provider call: estimated input ≤ input budget
- Tool-round cap configurable (`max_tool_rounds`, default 8) for research loops without an infinite loop

### ToolResult budget

- `ToolResultBudgetManager` is a second safety layer on every ToolResult
- Shared per-turn budget; family caps for web/Gmail/GitHub/Storage/group
- Trim content/excerpts first; keep success/error/ids/`truncated`/metadata
- Exhausted → `tool_context_budget_exceeded`

### Summary compaction

- Existing `UpdateConversationSummaryJob` / `ConversationSummaryService`
- Refresh on message **or** token threshold
- Incremental: previous summary + capped unsummarized range
- Coverage already on `from_message_id` / `to_message_id` — **no migration**
- Summary size capped; recompress if needed
- Raw messages never deleted

### Diagnostics

- Log `context budget` metrics only (user/conversation ids, model, estimated tokens, per-source counts, trimmed, utilization, overflow_prevented)
- Compact copy on `metadata.ai.context`
- No private texts. No new admin subsystem. No new DB table.

---

## Migration

None. No `web_pages` / `search_results` / `web_cache`. Production tables were not altered.

---

## Verification (allowed only)

Intended static checks (not product tests):

- `composer dump-autoload`
- `vendor/bin/pint --dirty`
- `php artisan migrate:status`
- `php artisan route:list`
- `php artisan schedule:list`
- `php artisan queue:failed`
- `npm run build`

**TESTS NOT RUN** (Owner decision). No PHPUnit. No `php artisan test`.

**NO LIVE WEB / AI:** no Tavily search, no outbound page fetch to the internet, no live Conversation AI, no Google, no GitHub.

Do not claim tested or live-validated.

---

## Known limitations

- Without `WEB_SEARCH_API_KEY`, tools return `web_search_not_configured`.
- HTML extraction is lightweight (no JS rendering).
- PDF fetch is out of scope.
- Token estimator is approximate (intentionally conservative).
- Current-turn images still consume a fixed image-token allowance; they are not dropped to keep old memories.
- Workspace UI still shows “Jarvis is thinking…” (no research-specific thinking state).
- Regular users do not get web tools in M22.3.

---

## Next

**M23 Voice Runtime Foundation.**
