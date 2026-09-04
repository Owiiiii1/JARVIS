# Голосовая архитектура

**Status.** MANUAL PASS (Owner, 2026-09-04/05): Voice starts, microphone/listening, hands-free end-of-turn after pause, Gemini STT, Jarvis reply, ElevenLabs TTS playback, M24.1.1 VAD hotfix.

Voice is a **modality** of Web Personal Workspace over an existing conversation. Not a second Jarvis, second memory, second User Space, or a separate client.

Desktop reuse is **not** a planned path (Desktop CANCELLED). Mobile may later call the same runtime; not current work.

```
Voice selection
  → microphone permission
  → listening
  → local VAD
  → automatic end-of-turn
  → Gemini STT
  → ConversationTurnService (same Core / tools / memory)
  → persisted messages
  → ElevenLabs TTS
  → playback
  → listening again
```

Mic button = **mute/unmute only** (committed M24.1). Same `conversation_id`. Persistence = ordinary `messages`. No second Voice brain. No continuous audio archive.

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

`VoiceRuntimeService` must not call Gemini Conversation AI / `AiChatGateway` directly.

UI Orb: [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

### Invariants

- same User Space
- same selected `conversation_id`
- same Conversation Engine
- same AI configuration of that space (STT/TTS do **not** change Conversation AI)
- same assistant personalization profile; TTS Voice ID is instance-level
- one memory; no `voice_memory` / `voice_messages`
- Text ↔ Voice must not create a new conversation
- final STT text and assistant text are ordinary `messages` rows
- `messages.channel` stays `web`; `messages.metadata.modality = voice`

---

## Runtime path (M23 + M24.1)

```
Text → Voice (user gesture)
        ↓
getUserMedia + session + listening
        ↓
MediaRecorder (pre-roll) + local VAD
        ↓
end-of-turn silence → Blob (canonical MIME + matching filename)
        ↓
POST /jarvis/voice/sessions/{id}/audio  (or /chat/...)
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
        ↓
TTS playback ends → listening + fresh recorder/VAD
```

No continuous vendor stream. No wake word (optional future research only; not Web-mandatory). Mute discards unsent audio. Switching conversation while Voice is active ends the old session.

M24.1.1 VAD: each listen cycle calibrates ambient RMS (~650ms) without dropping MediaRecorder pre-roll. Detection uses unamplified `rawInputRms`; Orb visualization uses a separate `* 3.2` gain. Speech starts above `startThreshold`, silence/end uses a lower `endThreshold`. `endSilenceMs` stays 850. `?voice_debug=1` for throttled metrics.

MIME: `VoiceAudioMime` canonicalizes `audio/webm;codecs=opus` → `audio/webm`. Upload filename matches the container.

`resume` is `muted → idle`. Frontend then calls `listen` exactly once. Recoverable `voice_session_invalid_state` fetches a snapshot; no full page refresh required.

Domain layer is transport-neutral. Production Web uses authenticated session + CSRF HTTP JSON. No WebRTC. A future Mobile client would call the same `VoiceRuntimeService`; there is no Desktop client.

M23 generates full assistant text before TTS. Later (Phase C): streaming STT/TTS if valuable.

---

## Voice Runtime vs Voice UI

**Runtime** (this document): session, STT, TTS, turn pipeline, events, interrupt/mute.

**UI:** Orb, transcript, mute/interrupt/end. One mic = mute.

---

## Provider ports

- `SpeechToTextProvider` → `SpeechTranscript`
- `TextToSpeechProvider` → `SynthesizedSpeech`

Managers: `SpeechToTextManager`, `TextToSpeechManager`. Null providers: `voice_stt_not_configured` / `voice_tts_not_configured`.

STT: `none` | `gemini` | `openai` (Whisper optional).  
TTS: `none` | `elevenlabs`.

Recommended: **STT = Gemini**, **TTS = ElevenLabs**. Conversation AI stays role configs.

### Gemini STT

`models.generateContent` (`v1beta`), **separate** from chat `GeminiClient`. Default model `gemini-3.5-transcribe` (Admin-editable). Live streaming model is **not** used.

Request: `inlineData` + `generationConfig.audioTranscriptionConfig` as a JSON **object** (empty config must be `{}`, not `[]`). Auto language detection by default.

STT is instance-level Admin infrastructure. Ordinary users do not configure it.

---

## Sessions

`voice_sessions`: `public_id`, `user_id`, `conversation_id`, `origin` (`web`; enum also lists `desktop`/`mobile` as leftover values, not planned Desktop work), `status`, STT/TTS used, activity timestamps, `error_code`, `metadata`.

Admin: singleton `voice_settings`. No `user_voice_settings`.

### State machine

`connecting`, `idle`, `listening`, `transcribing`, `thinking`, `speaking`, `interrupted`, `muted`, `error`, `ended`.

Invalid transitions → `voice_session_invalid_state`.

---

## Events

`session.started`, `state.changed`, `listening.started`, `transcript.partial`, `transcript.final`, `assistant.thinking`, `assistant.text`, `audio.started`, `audio.chunk`, `audio.ended`, `interrupted`, `muted`, `resumed`, `error`, `session.ended`.

No provider keys, raw tool JSON, system prompts, or stack traces.

---

## Audio

DTO `VoiceAudioChunk`. Hard bounds in `config/voice.php`.

Ephemeral: private temp disk → STT → delete. Failure: short retry window. `jarvis:voice:cleanup-temp` every five minutes.

Long-term source of truth is the **transcript**, not the recording.

---

## Interruption / mute

Interrupt: cancel TTS playback, state `interrupted`, next utterance. Do **not** delete already-persisted assistant text; set `messages.metadata.voice_playback_interrupted=true`.

Mute = input off. Not session end.

---

## Presentation hint

Optional Admin toggle `spoken_style_enabled`: spoken-aloud brevity. Not a second personality.

---

## Tools, budget, security, observability

Same tools and confirmation policy. Same ContextBudgetManager. Auth: session user owns the session and conversation. Log latencies and byte lengths; **never** audio bytes, transcripts, or secrets.

Errors: `voice_session_not_found`, `voice_session_invalid_state`, `voice_session_limit_reached`, `voice_audio_too_large`, `voice_audio_format_unsupported`, `voice_stt_not_configured`, `voice_stt_failed`, `voice_stt_rate_limited`, `voice_stt_timeout`, `voice_tts_not_configured`, `voice_tts_failed`, `voice_session_expired`, `voice_microphone_unavailable`, `voice_runtime_failed`.

---

## Out of scope

- Telephony / SIP / PSTN
- Wake word as a Web requirement
- Desktop client
- Continuous audio archive
