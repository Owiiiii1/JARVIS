# Natural conversation (Phase C)

**Status.** Basic Voice I/O is **MANUAL PASS**. This document is **future** conversational intelligence, not a redo of VAD / hands-free.

ADR-010 still applies: this is a layer over Core, Memory, and Voice — not “a better prompt”.

Desktop is cancelled. Do not wait on a native shell.

---

## Already done (do not plan again)

- Local VAD / hands-free end-of-turn
- Mute as the single mic control
- Barge-in foundation during TTS
- Gemini STT → ConversationTurnService → ElevenLabs TTS
- Same conversation_id and ordinary messages
- Spoken-style presentation hint

---

## Future improvements

- Lower latency
- Streaming STT / TTS if valuable
- More robust barge-in / overlap
- Better short-pause policy (pause ≠ always end of thought — refine, do not invent from scratch)
- Text generation cancellation when the user sends a new turn
- Incomplete phrases / pronouns
- Topic continuity and return-to-topic
- Clarification policy
- Stable personality (profile + General Prompt; not per-channel copies)
- Better working memory
- Natural conversational initiative (bounded; see [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md))
- Telegram Voice Replies: channel delivery of assistant text via `sendVoice` (not a second Voice Core; [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md))

**Wake word:** not mandatory. Limited value in a normal browser. Optional research for mobile/native or always-open environments.

---

## What this phase does not do

- Rewrite `messages` / `conversations`
- A second AI core “for voice”
- Hide a hardcoded client prompt that replaces platform prompt / General Prompt
- Unsolicited generic chatter
