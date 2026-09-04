# Cursor Work Report — M23 Voice Runtime Foundation

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git  
**Branch:** `main`

---

## Before

Origin/main HEAD before this work:

`c5d394603dbf8aa331a0d49a6db35038dbb6899c`  
`docs: record manual validation of web vision and storage`

Production MySQL `jarvis`. M22 / M22.1 / M22.2 / M22.3 / M22.3.1 already shipped. Web Search / image vision / Storage file read remain Owner MANUAL PASS. Voice was a Workspace placeholder only.

---

## DB backup

Before migrate, relevant production tables were dumped outside the git tree (no secrets in the repo):

`/home/deploy/backups/jarvis/m23-pre-migrate-20260904190716.sql`

Tables: `users`, `conversations`, `messages`. Voice tables are additive (`voice_sessions`, `voice_settings`). Migration `2026_09_04_220000_create_voice_sessions_tables` ran as **batch 18**.

---

## What changed

Voice is a modality over an existing conversation. Audio → STT → ordinary user text turn → existing `ConversationTurnService` → tools/memory/web/storage → persisted assistant message → TTS. `VoiceRuntimeService` does not call Conversation AI / Gemini directly.

---

## Schema

### `voice_sessions`

id, public_id UUID unique, user_id, conversation_id, origin (`web|desktop|mobile`), status, nullable stt_provider / tts_provider, started_at, last_activity_at, ended_at, error_code, bounded metadata JSON, timestamps.

Indexes: user_id, conversation_id, status, started_at.

Session is always tied to `user_id` + an already-owned `conversation_id`. Text ↔ Voice does not create a new conversation.

### `voice_settings`

Singleton Admin technical settings: stt_provider, tts_provider, spoken_style_enabled, encrypted elevenlabs_api_key, optional elevenlabs_voice_id.

Not created: `voice_messages`, `voice_memory`, `raw_audio_archive`, `user_voice_settings`.

---

## State machine

Canonical: connecting, idle, listening, transcribing, thinking, speaking, interrupted, muted, error, ended.

`VoiceSessionStateMachine` rejects invalid transitions (`voice_session_invalid_state`). Same-state is a no-op.

---

## STT / TTS

Contracts: `SpeechToTextProvider`, `TextToSpeechProvider`. Managers resolve the configured provider. Optional future `RealtimeDuplexSpeechProvider` is not the Core.

Null providers: `voice_stt_not_configured` / `voice_tts_not_configured` (safe, not a fatal process crash).

TTS: `ElevenLabsTextToSpeechProvider` structurally ready; Admin configured status only.

STT: OpenAI Whisper adapter on the dedicated transcriptions API (reuses OpenAI `ai_provider_settings` key, not Conversation AI `chat()`). Default STT is `none`. Gemini-as-STT deferred to M23.1 to avoid contaminating Conversation AI.

Voice STT/TTS selection does not change Owner Conversation AI.

---

## VoiceRuntimeService

create/start (ownership), listen, accept finalized audio blobs, STT, persist transcript via `ConversationTurnService`, obtain persisted assistant reply, TTS, session events, interrupt / mute / resume / end.

Voice-origin messages: `channel=web`, metadata `modality=voice` + voice session public id. Same User General Prompt plus optional bounded spoken-style presentation hint (configurable). Same tools and confirmation cards. Same `ContextBudgetManager` — long sessions cannot grow an unbounded prompt because transcripts are ordinary messages.

Interrupt: cancel playback, state `interrupted`, do **not** delete already-persisted assistant text; `voice_playback_interrupted=true`.

Mute: microphone/input off, not session/AI/memory end.

---

## Temp audio

Incoming audio is private/ephemeral → STT → delete after success. Failure keeps a short retry window. `jarvis:voice:cleanup-temp` every five minutes. Does not delete transcripts/messages.

`config/voice.php` bounds: chunk bytes, utterance seconds, mimes, sessions/user, inactivity, TTL, TTS text cap, provider timeouts.

---

## Routes (Owner Workspace, session + CSRF)

- POST `/jarvis/chats/{conversation}/voice/sessions`
- GET `/jarvis/voice/sessions/{session}`
- POST `.../listen`, `.../audio`, `.../interrupt`, `.../mute`, `.../resume`
- DELETE session

Future Desktop/Mobile: same `VoiceRuntimeService` via Client API (documented, not implemented).

---

## Workspace Voice MVP

`VoiceSession` replaces the M22 placeholder. Start Voice requests the microphone (user gesture only). End, Mute, session state, dynamic MIME, unsupported-browser state, transcript, assistant text, basic playback, CSS orb by state. Switching Voice → Text ends the session and keeps the same conversation. No Three.js Orb (M24). No telephony.

---

## Admin

Settings → Integrations → Voice/Speech: STT/TTS selected provider, configured/not configured, ElevenLabs configured status, spoken-style toggle. No plaintext secrets. No Test Connection.

---

## Observability

Safe logs: session ids, state, provider name, audio byte length/duration, STT/AI/TTS latency, interrupt count, error codes. Latency timestamps on the session. No audio bytes, transcript/assistant contents, or secrets.

---

## Verification (allowed only)

Static/build/schema/route/schedule checks:

- `php artisan migrate --force` — batch 18, `2026_09_04_220000_create_voice_sessions_tables`
- `php artisan migrate:status`
- `php artisan route:list --name=jarvis.voice` — 8 routes
- `php artisan schedule:list` — `jarvis:voice:cleanup-temp` every five minutes
- `php artisan queue:failed` — none
- `composer dump-autoload`
- `vendor/bin/pint --dirty`
- `npm run build` — pass

**TESTS NOT RUN** (`php artisan test` / PHPUnit not executed).

**NO LIVE STT / TTS / AI / Google / GitHub / Web smoke.** No live microphone-to-provider calls during implementation.

Do not claim live voice validation.

---

## Known limitations

- Without configured STT, utterance POST returns `voice_stt_not_configured`.
- Without configured TTS, the turn still persists; playback is skipped with `voice_tts_not_configured`.
- M23 Web sends one recorded utterance blob per turn (not duplex streaming).
- Confirmation UX in Voice is the existing Workspace card, not spoken confirmations.
- Final Orb, telephony, versioned Client API, and Owner live voice PASS are later.

---

## Next

**M24 Voice UI / Orb.**
