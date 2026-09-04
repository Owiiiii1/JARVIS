# Cursor Work Report — M23.2 Gemini STT Provider

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git  
**Branch:** `main`

---

## Before

Origin/main HEAD before this work:

`291e248693e93db04c8ff1f935ce27ad19535f79`  
`feat: add shared personal workspace for users`

Working tree was clean vs `origin/main`.

---

## Official Gemini transcription API verified

Checked current Gemini Developer API docs (ai.google.dev audio / generateContent) and the official Google Cloud `gemini_3_5_transcribe` notebook (Agent Platform, `generate_content`).

| Item | Value used |
| --- | --- |
| API family | Gemini API `models.generateContent` (`v1beta`) |
| Endpoint shape | `POST {base}/models/{model}:generateContent` |
| Default model id | `gemini-3.5-transcribe` |
| Auth | existing Gemini `ai_provider_settings` key (`x-goog-api-key`) |
| Request | `contents[].parts[].inlineData` + `generationConfig.audioTranscriptionConfig` |
| Language | auto-detect by default; optional `languageCodes` hint only |
| Not used | Live API / `gemini-3.5-transcribe-live`; Interactions API; `AiChatGateway`; chat prompts/tools |

Google Cloud notebook uses `gemini-3.5-transcribe-preview` for synchronous `generate_content`. The Gemini Developer API / public model name used as the Jarvis default is `gemini-3.5-transcribe`. Admin STT model is editable so the preview id can be stored without a code change.

Official audio-understanding MIME types: `audio/wav`, `audio/mp3`, `audio/aiff`, `audio/aac`, `audio/ogg`, `audio/flac`.

Browser MediaRecorder (M23) also produces `audio/webm`, `audio/ogg`, `audio/mp4`. Those captured types are sent through the same `inlineData` mechanism (`Part.from_bytes` / REST `inlineData`). If Gemini rejects the MIME, the adapter maps to `voice_audio_format_unsupported`. `audio/3gpp` is allowed by Jarvis temp storage but is not in the Gemini STT allowlist.

No auth examples or secrets are documented here.

---

## GeminiSpeechToTextProvider

Created `app/Services/Voice/Providers/GeminiSpeechToTextProvider.php`.

- Implements `SpeechToTextProvider`.
- Accepts `VoiceAudioChunk`.
- Validates Jarvis + Gemini size/duration bounds (`effective = min`).
- Maps MIME, calls official `generateContent`, normalizes `SpeechTranscript` (`text`, `is_final=true`, nullable language/confidence, bounded metadata).
- Does not call Conversation AI / `AiChatGateway` / `GeminiClient::chat`.
- Does not persist the raw Gemini body.

`SpeechToTextManager` keys:

- `gemini` → `GeminiSpeechToTextProvider`
- `openai` → existing `OpenAiSpeechToTextProvider` (kept)
- `none` → existing `NullSpeechToTextProvider` (kept)

`VoiceRuntimeService` still calls `$this->stt->transcribe(...)`. No vendor `if`.

---

## Credential reuse

`App\Services\Ai\GeminiCredentialResolver` reads `ai_provider_settings` where `provider = gemini`.

Configured = row exists, `is_connected`, API key present (Laravel encrypted cast).

No `GEMINI_STT_API_KEY`. No Voice-specific Gemini secret. No plaintext key in `voice_settings`. Admin Voice/Speech has no Gemini API key field.

Web Research Gemini Google Search now uses the same resolver for credential lookup (still a separate search path, not STT).

---

## STT model

Additive nullable column `voice_settings.stt_model` (default empty → `gemini-3.5-transcribe` for Gemini).

Admin: Settings → Integrations → Voice/Speech → STT Provider `None` / `Gemini` / `OpenAI`, plus STT model text when Gemini is selected.

---

## Error mapping

| Code | When |
| --- | --- |
| `voice_stt_not_configured` | Missing/unusable Gemini credential or empty model |
| `voice_stt_failed` | Provider/empty transcript/other failure |
| `voice_audio_format_unsupported` | MIME not allowed or Gemini invalid-argument media |
| `voice_audio_too_large` | Over Jarvis or Gemini inline bound / duration |
| `voice_stt_rate_limited` | HTTP 429 (no aggressive retry) |
| `voice_stt_timeout` | Connect/timeout after one transient retry |

Retry: at most one retry for network/5xx. No retry on 4xx auth/validation. No raw provider body to the frontend. No secrets/audio/transcript in logs.

Logs (safe): voice session public id, `provider=gemini`, model, mime, `audio_bytes`, duration if known, latency, result code.

---

## VoiceSettings / Admin

`VoiceSettingsService` remains the source of truth for STT provider/model/configured status.

Gemini STT ready only if:

- `stt_provider=gemini`
- existing Gemini credential connected/usable
- model non-empty

No live Test Connection. ElevenLabs key was not overwritten or removed.

---

## Migration

Additive only: `2026_09_04_230000_add_stt_model_to_voice_settings_table.php`.

Pre-migrate backup of `voice_settings` and `ai_provider_settings` written under `storage/backups/` (gitignored). Not committed.

No destructive change. ElevenLabs encrypted key column untouched.

---

## Static verification

Allowed checks only:

- `composer dump-autoload`
- `vendor/bin/pint --dirty`
- `php artisan migrate:status`
- `php artisan migrate` (additive `stt_model`)
- `php artisan route:list`
- `npm run build`
- static inspection

---

## TESTS NOT RUN

`php artisan test` / PHPUnit were **not** run.

---

## NO LIVE STT/TTS/AI

No live Gemini transcription. No live microphone-to-Gemini. No live ElevenLabs. No live Conversation AI. No live Google/GitHub/Web smoke.

---

## Recommended config

- **STT = Gemini**
- **TTS = ElevenLabs**

Conversation AI stays Default User / Owner role configs (unchanged; currently Gemini chat). Ordinary users do not configure STT.

---

## Known limitations

- Live Voice smoke is still Owner-deferred; configured status is local, not a live probe.
- Chrome MediaRecorder `audio/webm` is not on the official Gemini audio-understanding MIME list; it is forwarded via `inlineData` and safely rejected if Gemini refuses it.
- Dedicated Live/streaming transcribe model is not wired (M23 remains HTTP utterance blobs).
- OpenAI STT adapter remains unused unless Admin selects it and an OpenAI credential exists.
- Admin must select Gemini in Voice/Speech if the stored provider is still `none`.

---

## Docs / ADRs

Updated: `VOICE_ARCHITECTURE.md`, `AI_PROVIDER_ARCHITECTURE.md`, `INTEGRATIONS.md`, `CLIENTS/VOICE_UI.md`, `CLIENTS/WEB_WORKSPACE.md`, `CURRENT_STATE.md`, `IMPLEMENTATION_PLAN.md`, `ROADMAP.md`, `DECISIONS.md`, `DATABASE.md`.

ADRs 201–208: Gemini STT ≠ Conversation AI; credential reuse; instance Admin STT; users do not configure STT; auto language; vendor-neutral runtime; OpenAI optional; live validation deferred.
