# Cursor Work Report — M22.3.1 Web Research Admin Settings

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git  
**Branch:** `main`

---

## Before

Origin/main HEAD before this work:

`bd1e1a994135200d0f813e9c2367a9bbbe7ef811`  
`feat: add web research and context budgeting`

M22.3 Web Research + Context Budget Manager was already on origin/main (not live-validated). Conversation Engine was not rewritten.

### Local Gemini Google Search changes existed before this task

Yes. Uncommitted work at inspect (kept and integrated, not discarded):

- Modified: `.env.example`, `Docs/AI_PROVIDER_ARCHITECTURE.md`, `Docs/CURRENT_STATE.md`, `Docs/DECISIONS.md`, `Docs/INTEGRATIONS.md`, `Docs/WEB_RESEARCH.md`, `app/Providers/AppServiceProvider.php`, `app/Services/Ai/DefaultRolePrompts.php`, `app/Services/Conversations/ConversationContextBuilder.php`, `app/Services/Tools/WebResearch/SearchWebTool.php`, `app/Services/WebResearch/Providers/NullWebSearchProvider.php`, `config/web_research.php`
- Untracked: `app/Services/WebResearch/Providers/GeminiGoogleSearchProvider.php`, `tests/Feature/WebResearchTest.php`

That work already added `GeminiGoogleSearchProvider` behind `WebSearchProvider` (no second Web Research subsystem, no Google payload in Conversation Engine). This milestone completed it as the Admin-selectable `gemini_google` implementation and added persistent Admin settings on top.

---

## Architecture integrated

```
search_web
  ↓
WebSearchManager
  ↓
WebSearchProvider
     ├── GeminiGoogleSearchProvider   (gemini_google)
     ├── TavilyWebSearchProvider      (tavily)
     └── NullWebSearchProvider        (disabled)
```

- `search_web` / `fetch_web_page` contracts unchanged.
- Conversation Engine and `SearchWebTool` stay vendor-neutral.
- `fetch_web_page` remains `WebPageFetchService` + `WebUrlGuard` (not Gemini grounding).
- No second Web Research stack. `AutoWebSearchProvider` was not resurrected.

### Provider matrix

| provider | search | fetch | credential |
| --- | --- | --- | --- |
| `gemini_google` | Google Search grounding | `WebPageFetchService` | existing Gemini `ai_provider_settings` |
| `tavily` | Tavily search API | `WebPageFetchService` | encrypted Admin Tavily key, else `WEB_SEARCH_API_KEY` |
| `disabled` | no | no | none |

---

## Settings persistence

Singleton table `web_research_settings` (no generic system-settings table existed).

Non-secret fields: `enabled`, `provider`, `max_search_results`, `max_searches_per_turn`, `max_fetches_per_turn`, `max_page_chars`, `max_total_web_chars`, `fetch_web_page_enabled`, `timeout_seconds`, `default_recency_days`, `updated_at`.

Encrypted column `tavily_api_key` (Laravel `encrypted` cast, hidden from serialization). Gemini key is **not** stored here.

Runtime source of truth: `WebResearchSettingsService`. Tools/managers/trackers do not independently `config()`/`env()`/query settings.

### Precedence

1. Persisted Admin `web_research_settings`
2. env / `config/web_research.php` fallback
3. Safe defaults

Then `effective = min(value, hard safety ceiling)` with floors.

Tavily credential (separate): Admin encrypted key if set, else `WEB_SEARCH_API_KEY`. Clearing Admin key restores env fallback.

Gemini credential: `AiProviderSetting` where `provider=gemini` (`is_connected` + encrypted `api_key`). Not duplicated.

---

## Migration

Additive: `2026_09_04_210000_create_web_research_settings_table.php`.

Seeds one row from current env/config (provider/limits). Does **not** copy Tavily env key into the encrypted column (env remains fallback until Admin sets a key).

Backup of `ai_provider_settings`, `integration_accounts`, `telegram_bot_settings` taken before `php artisan migrate --force`. No destructive schema changes.

---

## Admin UI

Settings → Integrations → Web Research card (`WebResearchPanel`).

Status: Enabled/Disabled, active provider, Ready / API key required / Gemini not configured / Disabled, fetch effective, effective limits.

Editable: enable, provider select (Gemini Google Search / Tavily / Disabled), fetch toggle, results per search, max searches/fetches per turn, max page/total chars, timeout, optional default recency.

Gemini section: uses existing credential; Gemini configured yes/no; Google Search available yes/no; no secret input.

Tavily: set/replace key, configured yes/no, clear stored key. Never returns plaintext.

No Test Connection. No SSRF/private-IP/scheme/redirect/prompt-injection switches.

---

## Workspace read-only status

`/jarvis` context integrations list prepends:

- `Web Search · Google`
- `Web Search · Tavily`
- `Web Search · Disabled`

No editing, no technical limits in Workspace.

---

## Hard safety ceilings

`config/web_research.php` floors/ceilings:

- results 1–20
- searches/turn 1–10
- fetches/turn 0–10
- page chars 500–20000
- total web chars 1000–40000
- timeout 2–60s

`TurnBudgetTracker` and `WebPageFetchService` use effective settings. `ContextBudgetManager` / `ToolResultBudgetManager` remain the global layer. SSRF stays non-configurable.

Disabled: `search_web` → `web_search_disabled`; `fetch_web_page` → `web_research_disabled`. Fetch toggle off: `web_fetch_disabled` (search still works).

---

## Static verification

Allowed checks only (see below in session). **TESTS NOT RUN.** **NO LIVE WEB/AI.** No PHPUnit. No live Google Search, Tavily, page fetch, Conversation AI, Gmail, or GitHub.

---

## Known limitations

- Status is configuration presence only (no live smoke).
- Gemini `recency_days` is a prompt hint, not a native Google days filter. `domains` / `exclude_domains` are prompt + post-filter; non-matching hits are dropped.
- Grounding URLs may still be `vertexaisearch.cloud.google.com` when the original URL is not in the query string (no HTTP unwrap).
- HTML extraction is lightweight (no JS). PDF fetch out of scope.
- Regular users do not get web tools.
- Queue workers may hold process-lifetime objects; HTTP requests resolve settings per request.

---

## Next

**M23 Voice Runtime Foundation.**
