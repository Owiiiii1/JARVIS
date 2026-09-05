# Голосовая архитектура

**Status.** Voice pipeline MANUAL PASS (Owner, 2026-09-04/05). Current Web UX is push-to-talk («Рация»): hold to record, release to send. Hands-free dialogue/VAD capture was removed on 2026-09-05.

Voice is a **modality** of Web Personal Workspace over an existing conversation. Not a second Jarvis, second memory, second User Space, or a separate client.

Desktop reuse is **not** a planned path (Desktop CANCELLED). Mobile may later call the same runtime; not current work.

```
Voice selection
  → microphone permission
  → listening
  → hold push-to-talk
  → release to end turn
  → Gemini STT
  → ConversationTurnService (same Core / tools / memory)
  → persisted messages
  → ElevenLabs TTS
  → playback
  → ready for the next push-to-talk turn
```

The separate mic button is **mute/unmute**; the large push-to-talk button controls recording. Same `conversation_id`. Persistence = ordinary `messages`. No second Voice brain. No continuous audio archive.

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
- same assistant personalization profile; TTS Voice ID is a per-user preference (`users.voice_id`) with an instance fallback
- one memory; no `voice_memory` / `voice_messages`
- Text ↔ Voice must not create a new conversation
- final STT text and assistant text are ordinary `messages` rows
- `messages.channel` stays `web`; `messages.metadata.modality = voice`

---

## Runtime path (M23 + M24.1)

```
Text → Voice (user gesture)
        ↓
getUserMedia + session ready
        ↓
hold push-to-talk → MediaRecorder
        ↓
release push-to-talk → Blob (canonical MIME + matching filename)
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
TTS playback ends → ready for the next held turn
```

No continuous vendor stream. No wake word (optional future research only; not Web-mandatory). Mute discards unsent audio. Switching conversation while Voice is active ends the old session.

The normal turn boundary is explicit pointer hold/release, not silence VAD. Holding push-to-talk while Jarvis is speaking first interrupts playback, then records. The configured maximum utterance duration still bounds a held turn.

MIME: `VoiceAudioMime` canonicalizes `audio/webm;codecs=opus` → `audio/webm`. Upload filename matches the container.

`resume` is `muted → idle`. Frontend then calls `listen` exactly once and waits for push-to-talk. Recoverable `voice_session_invalid_state` fetches a snapshot; no full page refresh required.

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

Admin infrastructure remains singleton `voice_settings` (providers, key, fallback Voice ID). Each user selects one curated ElevenLabs voice in Workspace settings; the ID is stored as nullable `users.voice_id`. There is no `user_voice_settings` table. Resolution is explicit at Web/Telegram TTS boundaries through `ResolvesUserVoice`.

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
- Telegram Voice Replies as a second Voice Core (outbound delivery is implemented; it is still not Web Voice)

---

## Telegram voice delivery

**Status.** IMPLEMENTED / NOT VALIDATED. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

This is **not** Web Voice. It does **not** use the `voice_sessions` state machine.

Telegram DM text or voice note → Conversation Engine → persist canonical **text** → `TelegramReplyDeliveryService` → existing `TextToSpeechManager` (ElevenLabs MP3) → `sendVoice` when the delivery policy says so. ffmpeg is not used.

Voice notes use existing `SpeechToTextManager` / Gemini STT. No `voice_sessions`.

Default mode `text`. Tools: `get_telegram_response_mode` / `set_telegram_response_mode`. `auto` = voice-in → voice-out, text-in → text-out.

Canonical content is text. Audio is temporary (inbound STT or outbound TTS).

**Telegram Voice Input** is IMPLEMENTED / NOT VALIDATED. **Telegram Voice Replies** are MANUAL PASS.
