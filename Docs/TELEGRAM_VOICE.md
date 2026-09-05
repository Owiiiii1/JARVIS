# Telegram Voice Replies

**Status.** PLANNED / NOT IMPLEMENTED. Not current capability.

This document is the **target** architecture for Telegram voice **output**. Do not treat it as shipped.

Web Voice (hands-free STT/TTS in Personal Workspace) is **MANUAL PASS**. That does **not** mean Telegram can transcribe voice notes or send voice replies.

Related: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CHANNELS.md](CHANNELS.md), [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).

---

## Two directions (keep separate)

| | Direction | Current | Target |
| --- | --- | --- | --- |
| **A. Telegram Voice Input** | User sends a Telegram voice note → STT → Jarvis Core | **NOT IMPLEMENTED.** Paired DM rejects non-text (`UNSUPPORTED_MESSAGE_TYPE`). Group inbound stores a `[voice]` placeholder; no STT. | Optional later: download voice → reuse `SpeechToTextProvider` → ordinary user text turn |
| **B. Telegram Voice Reply** | Assistant text → TTS → Telegram `sendVoice` | **NOT IMPLEMENTED.** Outbound is `sendMessage` only. | This document |

This planning milestone is primarily **B**.

Web Voice MANUAL PASS does not imply A.

---

## Not a second Voice Core

Architecture remains:

```
Telegram inbound (today: text)
  → ConversationTurnService / Conversation Engine
  → same user, conversation_id, Memory, tools, Assistant Profile, General Prompt
  → assistant text persisted as a normal assistant message
  → response delivery policy decides text / voice
  → if voice:
        TextToSpeechManager / TextToSpeechProvider (existing)
        → audio bytes
        → normalize/convert to Telegram-compatible voice format
        → Telegram sendVoice
  → delivery complete
```

Same conversational agent. No Telegram-specific AI. No `voice_sessions` state machine for this path unless a later implementation has a specific reason.

Web Voice = interactive session (listen → VAD → STT → turn → TTS → listen).  
Telegram Voice Reply = **one-shot delivery** of an already-generated assistant text.

Do not mix the Web session state machine with Telegram delivery.

---

## Canonical content vs audio

- **Canonical conversation content** is the persisted assistant **text**.
- Generated audio is a **delivery representation** of that text.
- Memory, history, Web Workspace transcript, and later retrieval use text.
- Do not archive generated Telegram voice audio by default.

---

## Reuse existing TTS

Reuse `TextToSpeechManager` / `TextToSpeechProvider` (today: ElevenLabs; null if unconfigured).

Do **not** invent a Telegram-specific TTS provider in the target architecture.

TTS Voice ID remains **instance-level** provider configuration unless a future decision introduces per-user voice selection. That is independent of Telegram response mode and of assistant personality.

Current ElevenLabs config typically returns **MP3**. Telegram-native voice messages prefer **OGG / OPUS** for `sendVoice`. Server-side conversion (likely **ffmpeg**) may be required. ffmpeg is a **likely technical requirement**, not a shipped dependency, until an implementation milestone audits actual provider output and the server.

---

## Telegram format

Target native UX: Telegram Bot API **`sendVoice`**.

Preferred container: **OGG / OPUS** where Telegram requires it for a native voice-message bubble.

If conversion or `sendVoice` fails, send the canonical text (`sendMessage`). See fallback.

---

## Audio storage

Follow current Voice privacy: transcripts persist; recordings do not.

Preferred target:

1. Assistant text is persisted normally.
2. A temporary TTS artifact is generated.
3. It is delivered to Telegram.
4. Temporary audio is deleted after a bounded retry window.

No permanent archive of generated Telegram voice by default. A future audio archive would need a separate explicit ADR.

---

## Per-user response mode

Telegram response **medium** is a **user/channel delivery preference**, not AI provider config and not assistant personality.

Conceptual field (no migration in this documentation task):

`telegram_response_mode`: `text` | `voice` | `auto`

| Mode | Semantics |
| --- | --- |
| `text` | Always send normal Telegram text. |
| `voice` | Reply as a Telegram voice message where technically possible (still persist text; fallback to text on failure). |
| `auto` | Recommended default candidate: user sent Telegram **voice** → reply **voice**; user sent Telegram **text** → reply **text**. Exact default is chosen at implementation time. |

May live in a user/channel preferences domain. Do **not** put it on `user_assistant_profiles` unless an implementation audit concludes that is the right table.

Personality / name and Telegram response medium are separate concepts.

### Chat control (future)

Explicit user language should update the **same structured preference**, not an unreliable Memory fact:

- «Отвечай мне в Телеграме голосом.»
- «Отвечай текстом.»
- «На голосовые отвечай голосом.»

---

## Fallback

If TTS is unavailable, conversion fails, Telegram rejects the audio, or temp media generation fails: **still send the canonical text**. Voice delivery failure must not lose the assistant answer.

---

## Long answers

Do not blindly synthesize huge answers into multi-minute voice messages.

Implementation must set limits (not chosen here), for example:

- maximum text length / audio duration
- optional concise spoken rendering
- fallback to text for very large outputs

---

## What not to voice-render

Voice reply is for natural-language answers.

Do not voice-render:

- binary files
- large code blocks
- huge tables
- artifacts that need visual structure

Delivery policy may send text and/or files instead.

---

## Current code (audit, 2026-09-05)

- DM paired path: `TelegramUpdateHandler::handlePairedMessage` accepts **TEXT only**.
- Outbound: `Nutgram::sendMessage` only. No `sendVoice`.
- Groups: voice/audio inbound mapped to `[voice]` / `[audio]` placeholders; no transcription; groups do not auto-reply.
- Web Voice Runtime + Gemini STT + ElevenLabs TTS: **MANUAL PASS**. Separate from Telegram delivery.
