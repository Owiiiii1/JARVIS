# Natural conversation (Phase C)

**Status.** C.1 Conversation Intelligence is **IMPLEMENTED / NOT VALIDATED**. C.2 (streaming STT/TTS, barge-in robustness, hands-free VAD) remains **PLANNED**. Do not treat C.1 as MANUAL PASS until Owner live validation.

ADR-010 still applies: this is a layer over Core, Memory, and Voice — not “a better prompt”.

Desktop is cancelled. Do not wait on a native shell.

---

## Already done (do not plan again)

- Explicit push-to-talk turn boundary
- Separate mute control
- Push-to-talk interruption during TTS/thinking
- Gemini STT → ConversationTurnService → ElevenLabs TTS
- Same conversation_id and ordinary messages
- Spoken-style presentation hint
- Phase C.1 conversational working context (topic continuity, reference resolution, clarification policy, trusted recent tool refs, personality presentation, bounded initiative, frontend stale-response suppression)

---

## C.1 — Conversation Intelligence (this layer)

Same Conversation Engine. Web, Voice, and Telegram all go through `ConversationTurnService` → `ConversationAiService` → `ConversationContextBuilder`. There is no second Voice context builder and no Telegram-specific intelligence.

Working context is **derived**, conversation-scoped, and bounded. It is not a second memory engine and not a new message store. If extraction fails, the existing context path still runs.

Mutation tools never receive a guessed id. A unique trusted recent Core tool result (for example the task just created in this chat) may be used for a pronoun follow-up. Several plausible write targets → clarification.

Temporary style (“отвечай коротко”) stays in this conversation. It is not written to the assistant profile.

Web chat: a newer foreground send supersedes the previous in-flight **presentation**. The server does not cancel an already-running turn; already executed writes are not rolled back. Persisted user messages remain.

---

## Future improvements (C.2 and later)

- Lower latency
- Streaming STT / TTS if valuable
- More robust barge-in / overlap
- Optional future hands-free turn detection only if it is explicitly re-scoped and made reliable
- Server-side generation cancellation (beyond frontend stale suppression)

**Wake word:** not mandatory. Limited value in a normal browser. Optional research for mobile/native or always-open environments.

Telegram Voice Replies: channel delivery of assistant text via `sendVoice` (**MANUAL PASS**; [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md)).

Telegram Voice Input: DM voice note → existing Gemini STT (**IMPLEMENTED / NOT VALIDATED**).

---

## What this phase does not do

- Rewrite `messages` / `conversations`
- A second AI core “for voice”
- Hide a hardcoded client prompt that replaces platform prompt / General Prompt
- Unsolicited generic chatter
- Knowledge Graph / People graph
- Streaming STT/TTS in C.1
- Hands-free VAD restoration in C.1
