# Голосовая архитектура

**Status.** IMPLEMENTED / NOT VALIDATED (M23 Voice Runtime Foundation + M24 Voice UI / Orb, 2026-09-04). M25U.1 exposes the same runtime to ordinary users with capability `voice` via `/chat/.../voice/sessions` (aliases of the same controller as `/jarvis/...`). Automated tests not run. No live STT/TTS/AI. Telephony is out of scope.

Voice is a **modality** over an existing conversation. It is not a second Jarvis, second memory, second User Space, or a special voice chat.

```
audio input
  → STT
  → ordinary user text turn
  → ConversationTurnService
  → tools / memory / web / storage
  → persisted assistant message
  → TTS
  → audio output
```

`VoiceRuntimeService` must not call Gemini / Conversation AI directly.

UI Orb is separate: [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

### Invariants

- same User Space;
- same selected `conversation_id` (already owned by the user);
- same Conversation Engine (`ConversationTurnService` → `ConversationAiService`);
- same AI configuration of that space (Owner Conversation AI is **not** changed by STT/TTS provider selection);
- one memory; no `voice_memory` / `voice_messages`;
- Text ↔ Voice must not create a new conversation;
- final STT text and assistant text are ordinary `messages` rows;
- transport (`web`) and modality (`voice`) are distinct: `messages.channel` stays `web` for Workspace; `messages.metadata.modality = voice`.

---

## Same conversation

Every voice session stores `user_id` + `conversation_id`. The conversation must already belong to the user.

If Owner opens Voice inside `/jarvis/chats/{id}`, the session uses that conversation.

M23 Web policy: switching Voice → Text **ends** the active session cleanly. The thread already contains transcripts and replies.

---

## Runtime path (M23)

```
Browser MediaRecorder (short utterance blob)
        ↓
POST /jarvis/voice/sessions/{id}/audio
        ↓
VoiceTempAudioStore (ephemeral private disk)
        ↓
SpeechToTextManager → SpeechToTextProvider
        ↓
ConversationTurnService.handleUserMessage
        ↓
ConversationAiService + ContextBudgetManager + tools
        ↓
TextToSpeechManager → TextToSpeechProvider
        ↓
JSON events + optional audio bytes (HTTP)
```

Domain layer is transport-neutral. M23 Web uses authenticated session + CSRF HTTP JSON. No WebRTC/websocket infra was added. Desktop/Mobile later call the same `VoiceRuntimeService` via Client API.

M23 generates full assistant text before TTS. Later: LLM streaming → sentence chunks → TTS streaming. Interfaces do not require one giant recording for the whole conversation.

---

## Voice Runtime vs Voice UI

**Voice Runtime** (this document): session, STT, TTS, turn pipeline, events, interrupt/mute.

**Voice UI** ([CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md)): Orb, transcript, controls. M24 ships a provider-neutral Three.js Orb driven by `VoiceVisualizationState`. Runtime is unchanged.

---

## Provider ports

Canonical independent ports:

- `SpeechToTextProvider` — transcribe a complete audio chunk/file → `SpeechTranscript` (text, is_final, optional language/confidence, bounded provider metadata)
- `TextToSpeechProvider` — synthesize text → `SynthesizedSpeech` (bytes, mime, voice id, optional sample rate/duration, bounded metadata)

Managers: `SpeechToTextManager`, `TextToSpeechManager`. Runtime never instantiates vendor HTTP clients.

Null providers return safe errors: `voice_stt_not_configured` / `voice_tts_not_configured`. Not fatal exceptions for the PHP process.

Optional future: `RealtimeDuplexSpeechProvider` (telephony / vendor duplex). Not the Core. STT and TTS remain the canonical ports.

### M23 adapters

| Port | Implemented | Notes |
| --- | --- | --- |
| TTS | `ElevenLabsTextToSpeechProvider` + Null | Structural HTTP adapter. Configured status only in Admin. No live Test Connection. |
| STT | `OpenAiSpeechToTextProvider` (Whisper endpoint) + Null | Dedicated `/audio/transcriptions` API. Reuses OpenAI key from `ai_provider_settings` **without** going through Conversation AI `chat()`. Default provider is `none`. |

Gemini generateContent-as-STT is **not** wired (would contaminate Conversation AI). Concrete Gemini/other STT = **M23.1** if Whisper is not the production STT.

Selecting STT/TTS does **not** change Owner Conversation AI provider/model.

---

## Sessions

Table `voice_sessions`: `public_id` UUID, `user_id`, `conversation_id`, `origin` (`web|desktop|mobile`), `status`, nullable `stt_provider` / `tts_provider`, `started_at`, `last_activity_at`, `ended_at`, `error_code`, bounded `metadata` JSON.

Admin technical settings: singleton `voice_settings` (STT/TTS provider, spoken-style toggle, encrypted ElevenLabs key). Not Owner personal prefs. `user_voice_settings` is not created in M23.

### State machine (`VoiceSessionStatus`)

`connecting`, `idle`, `listening`, `transcribing`, `thinking`, `speaking`, `interrupted`, `muted`, `error`, `ended`.

Allowed transitions (`VoiceSessionStateMachine`; same-state is a no-op):

| From | To |
| --- | --- |
| connecting | idle, listening, error |
| idle | listening, muted, ended |
| listening | transcribing, interrupted, muted, ended, error |
| transcribing | thinking, listening, error, ended |
| thinking | speaking, interrupted, error, ended |
| speaking | listening, interrupted, muted, ended, error |
| interrupted | listening, thinking, ended |
| muted | idle, listening, ended |
| error | ended |
| ended | — |

Invalid transitions → `voice_session_invalid_state`.

---

## Events (`VoiceSessionEvent`)

Transport-neutral, client-safe:

`session.started`, `state.changed`, `listening.started`, `transcript.partial`, `transcript.final`, `assistant.thinking`, `assistant.text`, `audio.started`, `audio.chunk`, `audio.ended`, `interrupted`, `muted`, `resumed`, `error`, `session.ended`.

No provider keys, raw tool JSON, system prompts, hidden reasoning, or stack traces.

M23 Web returns events in the HTTP JSON body. GET session polls last bounded events.

---

## Audio

DTO `VoiceAudioChunk`: session public id, sequence, temp file path, mime, sample rate, channels, `is_final`, optional duration/captured_at.

`config/voice.php` hard bounds: chunk bytes, utterance seconds, allowed mimes, max sessions/user, inactivity timeout, session TTL, TTS text cap, provider timeouts.

**Ephemeral audio policy:** private temp disk → STT → delete after success. On STT failure retain only a short retry window. `jarvis:voice:cleanup-temp` every five minutes. Transcripts/messages are never deleted by this job.

Long-term source of truth is the final transcript. Optional future audio-recording retention is a separate product decision.

---

## Interruption / mute

`VoiceRuntimeService::interrupt`: stop/mark TTS playback cancelled, state `interrupted`, emit event, accept the next utterance.

If assistant text was already persisted before TTS, **do not delete it**. Set `messages.metadata.voice_playback_interrupted=true`. History stays what Jarvis intended to say.

Mute = microphone/input off. Not session end, not AI disable, not memory disable. `speaking|idle → muted → idle|listening`.

---

## Presentation hint

Voice uses the same User General Prompt. Optional bounded runtime instruction (configurable, Admin toggle `spoken_style_enabled`):

> Response will be spoken aloud; prefer concise natural spoken sentences unless detail is requested.

This is modality presentation context, not a second personality.

---

## Tools and confirmations

Voice uses the same Owner tools (reminders, Storage, Web Research, Google, GitHub, group knowledge). Confirmation policy is unchanged. Voice must not bypass confirmation because speech was used. Workspace can still show the confirmation card. M23 does not ship spoken confirmation UX.

---

## Context budget

Voice uses the same `ContextBudgetManager` / `ToolResultBudgetManager`. Transcripts are ordinary messages, so summary-first budget applies automatically. A 5-hour voice session must not create a 5-hour prompt.

---

## Security

Every endpoint: authenticated user, `session.user_id === user.id`, conversation owned by the same user. UUID alone is not authorization. Web Workspace uses session + CSRF. No public audio endpoints.

Rate bounds: Laravel throttle on voice routes + config session/chunk/utterance/inactivity limits.

---

## Observability

Log: session id / public id, state transition, provider name, audio **byte length** / duration, STT/AI/TTS latency, interrupt count, safe error code.

Do **not** log: audio bytes, transcript/assistant contents, secrets.

Latency timestamps in session metadata: capture complete, STT final, AI start/complete, TTS start/complete.

---

## Errors

`voice_session_not_found`, `voice_session_invalid_state`, `voice_session_limit_reached`, `voice_audio_too_large`, `voice_audio_format_unsupported`, `voice_stt_not_configured`, `voice_stt_failed`, `voice_tts_not_configured`, `voice_tts_failed`, `voice_session_expired`, `voice_microphone_unavailable`, `voice_runtime_failed`.

No raw provider body.

---

## Out of scope (explicit)

- Twilio Voice, SIP, PSTN, phone numbers, call recording, ElevenLabs phone agent
- Final Three.js Orb (M24)
- Live STT/TTS/AI validation in this milestone
- Automated tests (`php artisan test`)

Telephony is a future **adapter** over Voice Runtime, not a second engine.
