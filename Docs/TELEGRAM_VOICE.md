# Telegram Voice Replies

**Status.** IMPLEMENTED / NOT VALIDATED. Outbound only. Owner has not confirmed a live Telegram voice bubble yet.

Web Voice (hands-free STT/TTS in Personal Workspace) remains **MANUAL PASS**. That does **not** mean Telegram Voice Input exists.

Related: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CHANNELS.md](CHANNELS.md), [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md).

---

## Two directions

| | Direction | Status |
| --- | --- | --- |
| **A. Telegram Voice Input** | User voice note → STT → Core | **NOT IMPLEMENTED.** Paired DM still rejects non-text. Groups store `[voice]` placeholder. Separate future milestone. |
| **B. Telegram Voice Reply** | Assistant text → TTS → `sendVoice` | **IMPLEMENTED / NOT VALIDATED** |

This milestone is **B** only.

---

## Architecture

Not a second Voice Core. No `voice_sessions` for this path.

```
Telegram inbound text (DM)
  → TelegramUpdateHandler
  → ConversationTurnService
  → assistant text persisted (canonical)
  → TelegramReplyDeliveryService
       text | unsuitable | TTS fail | sendVoice fail → sendMessage
       voice success → sendVoice (no duplicate text)
```

Same user, `conversation_id`, Memory, tools, Assistant Profile, General Prompt.

Web Voice = interactive session.  
Telegram Voice Reply = one-shot delivery of already-generated assistant text.

---

## Preference

Table `user_channel_preferences` (`user_id` + `channel`, unique).  
`response_mode`: `text` | `voice` | `auto`.

**Default: `text`.** Missing row = text. Existing users do not change behavior after deploy.

| Mode | Behavior now |
| --- | --- |
| `text` | `sendMessage` only. No TTS. |
| `voice` | TTS + `sendVoice` when suitable; otherwise text fallback. |
| `auto` | Voice inbound → voice reply. **Inbound voice is not implemented**, so auto currently matches text for text inbound. Ready for later STT. |

Tools (current user only, capability `telegram_dm`, no `user_id` from the model, no confirmation modal):

- `get_telegram_response_mode`
- `set_telegram_response_mode`

Chat examples: «Отвечай голосом», «Отвечай текстом», «На голосовые отвечай голосом».

Not stored in Memory, General Prompt, or `user_assistant_profiles`.

---

## Format

Telegram `sendVoice` accepts OGG/OPUS, **MP3**, M4A.

ElevenLabs TTS (same instance Voice settings as Web) returns **MP3** (`audio/mpeg`, `mp3_44100_128`).

**MVP: MP3 → `sendVoice` directly. ffmpeg was not installed.**

---

## Fallback

One text `sendMessage` if:

- mode is text / auto+text inbound
- pending tool confirmation (buttons need text)
- unsuitable payload (large fenced code > 400 chars, markdown tables ≥ 4 rows, `artifact` fences, spoken length > **2000** characters)
- TTS unconfigured / TTS error
- incompatible MIME
- audio larger than `voice.max_audio_chunk_bytes` (2_000_000)
- temp write or `sendVoice` fails

Canonical assistant **text is never deleted**. No second assistant message. No duplicate success (voice + text).

Spoken rendering for TTS strips markdown locally (no extra LLM call). Persisted body stays unchanged.

---

## Temp audio

`voice-temp/telegram/{userId}/{random}.mp3` on the private voice disk. Deleted after send (success or fail). Stale files: existing `jarvis:voice:cleanup-temp`.

Not StoredFile. No archive.

---

## Out of scope (unchanged)

- Telegram Groups (no voice replies)
- Reminder dispatch (`⏰` still text via `TelegramBotManager::sendTextMessage`)
- Disabled users (existing reject path)
- Onboarding auto-enable
- Desktop / Mobile / Client API

---

## Owner manual checklist

1. Telegram: «Отвечай мне голосом» → Jarvis confirms the setting.  
2. Short test question → native voice bubble.  
3. Another question → still voice.  
4. «Отвечай текстом» → later replies text.  
5. Optional: if TTS is misconfigured, text fallback, answer not lost.

Default for Owner remains **text** until explicitly changed.
