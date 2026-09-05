# Cursor work report — Telegram Voice Replies documentation

**Date:** 2026-09-05  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`  
**Task:** documentation only — future Telegram Voice Replies

---

## Starting HEAD

| Item | Value |
| --- | --- |
| Branch | `main` |
| origin/main at start | `6e18325aae2d53c65d05b535f46c14bd9ee60ce8` |
| Message | `docs: realign Jarvis roadmap and current architecture` (M26D) |

Worked on top of M26D. Did not revert that realignment. Desktop remains CANCELLED. Web remains primary. Telegram remains a secondary adapter.

Uncommitted local Voice client/TTS experiments were **not** treated as shipped and were **not** committed.

---

## Files changed

- `Docs/TELEGRAM_VOICE.md` — **new** target architecture
- `Docs/ROADMAP.md` — Phase C enhancement
- `Docs/IMPLEMENTATION_PLAN.md` — PLANNED / DEFERRED milestone after M25U.3.1
- `Docs/CHANNELS.md` — current text vs future voice in/out
- `Docs/VOICE_ARCHITECTURE.md` — Telegram voice delivery section
- `Docs/ASSISTANT_PERSONALIZATION.md` — response mode ≠ personality
- `Docs/CURRENT_STATE.md` — not implemented
- `Docs/DECISIONS.md` — ADR-246–253
- `Docs/ARCHITECTURE.md`, `Docs/HUMAN_LIKE_ASSISTANT.md`, `Docs/CONVERSATION_ENGINE.md`, `Docs/USERS_AND_CABINET.md`, `Docs/PROJECT.md`, `Docs/README.md`
- this report

---

## New product decision

Jarvis may later reply in Telegram with a native **voice message**. That is adapter **delivery**, not a second Voice Core or a second conversational agent.

---

## Target Telegram voice reply flow

```
Telegram message
  → Jarvis Core (ConversationTurnService)
  → assistant text persisted as normal assistant message
  → delivery policy (text / voice / auto)
  → if voice: existing TextToSpeechProvider → convert if needed → sendVoice
  → on TTS/conversion/Telegram failure: send canonical text
```

Same user, `conversation_id`, Memory, tools, Assistant Profile, General Prompt.

---

## Current vs planned

| Item | Status |
| --- | --- |
| Web Voice (VAD, Gemini STT, ElevenLabs TTS, Orb) | MANUAL PASS |
| Telegram DM inbound | **text only** (`handlePairedMessage` rejects non-text) |
| Telegram DM outbound | **`sendMessage` only** |
| Telegram Voice Input (voice note → STT) | NOT IMPLEMENTED |
| Telegram Voice Replies (`sendVoice`) | PLANNED / NOT IMPLEMENTED |
| Group inbound voice | `[voice]` placeholder, no STT, no auto-reply |

Web Voice PASS does not imply Telegram transcription or voice replies.

---

## Response modes (future)

Conceptual `telegram_response_mode`: `text` | `voice` | `auto`.

- `text` — always Telegram text
- `voice` — voice message where technically possible
- `auto` — recommended default candidate: voice-in → voice-out; text-in → text-out

Per-user/channel delivery preference, not personality, not AI provider config. Chat commands update the same structured preference. No migration in this task.

---

## Fallback

TTS unavailable, conversion failure, Telegram reject, or temp media failure → still send canonical text. Answer must not be lost.

Do not voice-render files, large code, huge tables, visual artifacts. Long answers: future duration/length limits; not chosen here.

---

## Storage lifecycle

Text persists. Temporary TTS artifact → deliver → delete after bounded retry. No default audio archive. ffmpeg is a likely conversion need (OGG/OPUS for `sendVoice`); **not** installed or committed as a dependency in this task.

---

## Future milestone placement

Immediate executable remains **M25U.3.1 Web Reminders without Telegram**.

**Telegram Voice Replies** is a small independent Phase C item, PLANNED / DEFERRED, after reminder hardening. Not a new major phase. Not Desktop. Not a primary-client change.

---

## Checks

- `git fetch origin`; HEAD = M26D `6e18325`
- Telegram adapter audit: text-only DM; `sendMessage` only; groups `[voice]` placeholder
- Existing TTS: `TextToSpeechManager` / ElevenLabs (typical MP3)
- No product code, migrations, ffmpeg, settings, live TTS/Telegram tests, or DB writes

---

## NO PRODUCT CODE CHANGES

---

## NO LIVE TESTS
