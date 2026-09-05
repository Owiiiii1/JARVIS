# Production ↔ GitHub Reconciliation

Controlled reconciliation of the production checkout at `/var/www/jarvis` against `origin/main` (`Owiiiii1/JARVIS`). This was not feature work. Reminders, onboarding, tasks, and other roadmap milestones were not changed.

## Starting state

- Branch: `main`
- Local HEAD = `origin/main` = `58e845a9f5ef6de5ccf98d8c06917f17d1f6ace5` (`docs: complete current Jarvis state audit`)
- Ahead/behind: `0/0`
- Working tree: **dirty** (tracked provider/tooling files plus untracked Boost install)
- Production DB `jarvis` was not written. No `migrate:fresh`, no `RefreshDatabase`, no live Gemini / ElevenLabs / Telegram / AI.

## Dirty files found

### Tracked modified

- `CLAUDE.md`
- `app/Services/Voice/Exceptions/VoiceException.php`
- `app/Services/Voice/Providers/ElevenLabsTextToSpeechProvider.php`
- `app/Services/Voice/Providers/GeminiSpeechToTextProvider.php`
- `composer.json`
- `composer.lock`

### Untracked

- `.claude/`
- `.mcp.json`
- `boost.json`

### Ignored (left untouched)

`.env`, `vendor/`, `node_modules/`, `public/build/`, `storage/` logs and private disks, `.phpunit.result.cache`, `bootstrap/cache/*`, `database/database.sqlite`. These are local/runtime artifacts, not reconciliation candidates.

No additional dirty runtime files existed beyond the lists above.

## Classification

| File | Category | Explanation | Outcome |
| --- | --- | --- | --- |
| `app/Services/Voice/Providers/GeminiSpeechToTextProvider.php` | A REQUIRED PRODUCT FIX | Empty `audioTranscriptionConfig` must be JSON `{}`. origin/main sent `[]`. Error classification and bounded status/message logs are part of that fix. | Committed in runtime commit |
| `app/Services/Voice/Providers/ElevenLabsTextToSpeechProvider.php` | A REQUIRED PRODUCT FIX | Per-user `users.voice_id` can select a catalog voice the account cannot use. Needs one bounded fallback. Local 402 retry was **not** kept. | Committed (tightened) in runtime commit |
| `app/Services/Voice/Exceptions/VoiceException.php` | A REQUIRED PRODUCT FIX | Optional bounded `ttsFailed($context)` for HTTP status / voice id; callers without args still work. | Committed in runtime commit |
| `tests/Unit/Voice/*` | A REQUIRED PRODUCT FIX | Fake-HTTP unit coverage for the provider behavior. | Committed in runtime commit |
| `app/Services/Ai/GeminiCredentialResolver.php` | A REQUIRED PRODUCT FIX | `final` removed so tests can stub credentials without querying production `ai_provider_settings`. Runtime behavior unchanged. | Committed in runtime commit |
| `Docs/CURRENT_STATE.md` | A REQUIRED PRODUCT FIX | Record committed provider behavior. | Committed in runtime commit |
| `Docs/VOICE_ARCHITECTURE.md` | A REQUIRED PRODUCT FIX | Record `{}` request shape and bounded TTS fallback. | Committed in runtime commit |
| `Docs/Development/Cursor_Work_Report.md` | A REQUIRED PRODUCT FIX | This reconciliation record. | Committed in runtime commit |
| `composer.json` (`laravel/boost` require-dev) | B DEVELOPMENT TOOLING | Intentional Laravel Boost install from workspace agent bootstrap, not a runtime dependency. | Committed in separate chore commit |
| `composer.lock` | B DEVELOPMENT TOOLING | Lockfile for Boost and its require-dev tree. | Committed in separate chore commit |
| `CLAUDE.md` | B DEVELOPMENT TOOLING | Boost-generated Laravel guidelines replacing the previous bootstrap stub. | Committed in separate chore commit |
| `.claude/` | B DEVELOPMENT TOOLING | Boost skills (`laravel-best-practices`, `testing-best-practices`, Inertia/React, Tailwind, infer-conventions). No secrets. | Committed in separate chore commit |
| `boost.json` | B DEVELOPMENT TOOLING | Project Boost config (`cloud: false`, listed skills). | Committed in separate chore commit |
| `.mcp.json` | B DEVELOPMENT TOOLING | Project MCP: `php artisan boost:mcp` only. No tokens, keys, or absolute private paths. | Committed in separate chore commit |

No files were classified C (machine-only secrets config), D (accidental/stale discard), or E (unclear left in the tree).

## Gemini STT findings

Compared local working tree with `origin/main`.

1. **Does this fix real Gemini API compatibility?** Yes. PHP `[]` JSON-encodes as `[]`. Gemini proto for `audioTranscriptionConfig` expects a JSON object. Docs already required `{}`. The local `new \stdClass` empty config is the correct request shape.
2. **Could origin/main send the wrong JSON shape?** Yes. Committed `origin/main` called `transcriptionConfig()` directly, so an empty language hint serialized as `"audioTranscriptionConfig":[]`.
3. **Secret/PII leak risk in new logs?** Bounded. Extra fields are `gemini_status` and a 240-character `error.message`. `VoiceMetricsLogger` already unsets `audio`, `bytes`, `transcript`, `text`, `body`, `assistant`, `prompt`, `api_key`, `secret`. Raw request bodies, audio payloads, API keys, and transcripts are not added. `audio_bytes` remains a length integer (existing contract).
4. **Keep all local changes or only part?** Keep: `{}` encoding, tighter `looksLikeUnsupportedMedia()` (exclude `unknown name` / `json payload`; do not treat those 400s as unsupported MIME), and bounded status/message logs. Do not log the request payload.

Unit tests (HTTP fakes, no live Gemini): empty config is `{}` not `[]`; JSON-shape 400 is `voice_stt_failed`; MIME/audio-format 400 is `voice_audio_format_unsupported`; logged context does not contain the fake API key, audio marker, or transcript.

## ElevenLabs findings

Local diff added diagnostics, a fallback retry, and `VoiceException` context. The local retry also treated HTTP **402** as fallback-eligible. That is quota/payment and is **not** a voice-availability failure.

Answers:

1. If the user selected a voice ID the ElevenLabs account cannot use, origin/main failed that synthesis with no retry. Web Voice and Telegram Voice Replies would surface `voice_tts_failed` even though instance fallback exists in settings.
2. Yes, one fallback to the instance effective voice, then config `voice.elevenlabs.voice_id`, is needed for per-user catalog IDs that this account cannot speak. Skip fallback when it equals the preferred voice.
3. Fallback is a second billed TTS request. That is inherent. It is bounded to **one** extra request, and only when the first failure is classified as voice-unavailable. No loop.
4. Retry is allowed for HTTP 404 and for 4xx bodies that mark `invalid_voice`, `voice_not_found`, `library voice`, `unknown_voice`, or `voice does not exist`.
5. Auth (401/403), quota (402), rate limit (429), connection/transport, and generic 5xx **must not** retry another voice.
6. Diagnostics: do **not** store raw provider `detail` on the exception. Context is `reason`, `http_status`, `voice_id`, `voice_unavailable`. Fallback logs only `from_voice`, `to_voice`, `http_status`.

Committed behavior uses the existing `TextToSpeechProvider` / `synthesize(?string $voiceId)` path. User voice is requested first; isolation is preserved when the selected voice succeeds.

## Laravel Boost/tooling findings

This was a deliberate `composer require laravel/boost --dev` plus `php artisan boost:install`, triggered by the workspace Laravel agent bootstrap (`AGENTS.md`), not a random leftover.

- `laravel/boost` is **require-dev** only; production runtime does not depend on it.
- `.mcp.json` is project-safe: `php artisan boost:mcp`, no secrets.
- `.claude/` contains published Boost skills, not machine credentials.
- `boost.json` has `cloud: false`.

These files are useful for Cursor/Laravel agent workflow on this repo, so they are committed **separately** from the provider runtime fix. They are not local-only secrets config, so they were not deleted.

`.gitignore` was not changed. `/.cursor/` remains ignored.

## Changes committed

### Commit 1 — `fix: harden voice provider handling`

- Gemini STT empty `audioTranscriptionConfig` as `{}`
- Gemini JSON-shape vs MIME error classification
- Bounded Gemini provider log fields
- ElevenLabs one-shot voice-unavailable fallback to instance/config voice
- `VoiceException::ttsFailed(array $context = [])` with bounded context
- `GeminiCredentialResolver` is no longer `final` (test stubs only; no credential behavior change)
- Unit tests under `tests/Unit/Voice/`
- Docs: `Docs/CURRENT_STATE.md`, `Docs/VOICE_ARCHITECTURE.md`, this report

### Commit 2 — `chore: add Laravel Boost development tooling`

- `composer.json` / `composer.lock`
- `CLAUDE.md`
- `.claude/`
- `.mcp.json`
- `boost.json`

## Changes discarded

- ElevenLabs fallback on HTTP **402** (quota). Not committed.
- Storing raw ElevenLabs `detail` on `VoiceException` context. Not committed.
- No dirty files were deleted from disk after classification; Boost was kept and committed. Ignored runtime files were left ignored.

Every original dirty/untracked file:

1. `CLAUDE.md` → Boost commit
2. `app/Services/Voice/Exceptions/VoiceException.php` → runtime commit (kept optional context, no raw provider body)
3. `app/Services/Voice/Providers/ElevenLabsTextToSpeechProvider.php` → runtime commit (402 retry removed; instance fallback; bounded context)
4. `app/Services/Voice/Providers/GeminiSpeechToTextProvider.php` → runtime commit
5. `composer.json` → Boost commit
6. `composer.lock` → Boost commit
7. `.claude/` → Boost commit
8. `.mcp.json` → Boost commit
9. `boost.json` → Boost commit

## Tests/static checks

Allowed checks only. No `php artisan test` against production DB. No live providers.

- Targeted PHPUnit: `tests/Unit/Voice/VoiceExceptionTest.php`, `GeminiSpeechToTextProviderTest.php`, `ElevenLabsTextToSpeechProviderTest.php` (HTTP fakes, Schema facade stub so `voice_settings` is not read from production MySQL; sqlite PDO is not installed here)
- `vendor/bin/pint --dirty --format agent` on touched PHP
- `php -l` on touched PHP
- `composer validate`
- `git diff --check`
- `php artisan migrate:status` read-only

Frontend was not touched; `npm run build` was not run.

## Security review

- No secrets, tokens, or absolute private paths added to git.
- `.mcp.json` is a local artisan MCP command.
- `.env` remains gitignored.
- TTS/STT tests use fake keys and `Http::preventStrayRequests()`.
- Exception/log context does not include audio, transcripts, API keys, or raw provider bodies.
- Production identities/messages were not read or mutated for this work.
- `GeminiCredentialResolver` tests stub `apiKey()` so `ai_provider_settings` is not queried.

## Final repository state

After both commits and `git push origin main`:

- Local HEAD = `origin/main`
- Ahead/behind `0/0`
- `git status --short` empty
- Provider files on disk match the committed runtime versions
- `composer.lock` matches `composer.json`
- No untracked runtime/tooling debris
- GitHub is the source of the current production checkout

## Remaining risks

- Live Gemini STT was not called; `{}` vs `[]` is covered by unit tests and Gemini proto/docs, not a live 200 from Google.
- Live ElevenLabs was not called; a selected catalog voice may still be unavailable, in which case one fallback request now runs. Duplicate billing is possible for that one fallback only.
- Instance fallback voice may itself be unavailable; that failure is surfaced once.
- `UserCapabilitiesTest` (regular-user capability matrix) was **not** changed; it is out of this reconciliation scope.
- Panel reminder icon-only vs labeled entry remains a known UI issue; not in scope.
- sqlite PDO is not installed on this host; provider tests therefore stub `Schema` instead of using in-memory sqlite.

## Manual validation needed

Owner-approved live checks only, when wanted:

1. Web Voice push-to-talk: Gemini STT with empty language hint succeeds (no JSON-shape 400).
2. User with a catalog `users.voice_id` the account can use: one ElevenLabs request, that voice plays on Web and Telegram.
3. User with a catalog ID the account cannot use: one fallback to instance voice, no retry on 401/402/429/5xx.
4. Confirm logs show `voice.tts.voice_fallback` with voice IDs and HTTP status only, never audio or keys.
