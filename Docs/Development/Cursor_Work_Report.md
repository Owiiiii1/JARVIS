# Phase C.2 Beta — ElevenLabs Realtime Conversation

## Starting HEAD

`f92c474` (`feat: add conversational intelligence`). `git status` was clean. `HEAD` == `origin/main`.

Work ran on that main. No migration. Production database `jarvis` was not truncated, refreshed, or mass-updated. No live ElevenLabs / Telegram / Gemini / Gmail / Calendar / GitHub calls.

## Existing legacy voice

Web **Рация** is unchanged: MediaRecorder → upload → Gemini STT → `VoiceRuntimeService` → `ConversationTurnService` → ElevenLabs HTTP TTS. Push-to-talk UI, Gemini STT path, current fallback, and MANUAL PASS behavior stay. PTT audio is rejected on a realtime `voice_sessions` row so the two transports cannot mix.

## Telegram voice invariants

Telegram Voice Input remains: voice note → Gemini STT → `ConversationTurnService`.
Telegram Voice Replies remain: `ConversationTurnService` → ElevenLabs HTTP TTS → `sendVoice`.
Default text/auto/voice policy is unchanged. Telegram does not create an ElevenLabs realtime session, Twilio, or WebRTC. Constructor isolation tests lock that boundary.

## Realtime architecture

New parallel Web mode **Диалог Beta** (`/jarvis` and `/chat` only):

Browser ↔ ElevenLabs realtime (audio, STT, turn detection, pauses, barge-in, expressive TTS)
→ Jarvis Custom LLM adapter
→ `ConversationTurnService`
→ ElevenLabs realtime speech → Browser

Jarvis remains the only brain (conversation, Memory, ContextBudget, personality, Tasks/Reminders/Projects, Gmail/Calendar/GitHub, Web Research, tool policy, confirmations, isolation). ElevenLabs Agent tools/memory are not used.

## ElevenLabs session auth

`POST /jarvis|chat/chats/{conversation}/voice/realtime/session` authenticates the user, `ensureOwned` the personal conversation, creates a local `voice_session`, fetches a signed URL with `xi-api-key` on the server, and returns only ephemeral client fields (`signed_url`, opaque `adapter_token`, safe TTS override). The API key never enters the browser.

## Voice-session binding

Reuses `voice_sessions` without a migration. Metadata holds `provider=elevenlabs_realtime`, `voice_mode=realtime`, adapter token hash/expiry, optional `external_conversation_id`, and numeric latency. One realtime session binds one `conversation_id`. Starting a session for another chat ends the previous realtime session. End Voice does not delete the Jarvis conversation.

## Custom LLM adapter

`POST /api/voice/elevenlabs/chat/completions` (CSRF-exempt API route). Auth: `Authorization: Bearer` `ELEVENLABS_CUSTOM_LLM_SECRET` plus `elevenlabs_extra_body.jarvis_session_token`. The adapter resolves the local session → user + bound conversation. Body `user_id` / `conversation_id` are ignored. It calls `ConversationTurnService`, not Gemini/OpenAI directly, then SSE-streams the final assistant text (OpenAI chat.completion.chunk + `[DONE]`).

## Conversation persistence

Each final user transcript is a normal web user message: `channel=web`, `metadata.modality=voice`, `metadata.voice_mode=realtime`. Assistant text is a normal assistant message with the same metadata. Text and Voice share the Jarvis thread. ElevenLabs history is transport state only; Core rebuilds context each turn.

## Tools / confirmations

Tools stay inside Jarvis. Realtime cannot bypass confirmation. The Workspace confirmation card still appears in Voice mode. A Gmail/Storage-style write still requires the existing policy.

## Orb / UI states

`JarvisVoiceOrb` is reused. SDK modes map to `connecting`, `listening`, `user_speaking`, `thinking`, `speaking`, `interrupted`, `muted`, `error`, `ended`.

## Mode selector

Workspace Voice: **Рация** | **Диалог Beta**. Default Рация, stored in `localStorage` (`jarvis.voice.web_mode`). No schema change. Beta hides PTT. Рация UX is unmodified. If Beta cannot start, the UI offers «Переключиться на Рацию» and does not auto-forward the live mic to the legacy path.

## Streaming behavior

Phase 1: Core finishes the tool loop, then the adapter streams that final text. No speculative tokens before tools/confirmations. Phase 2 (later): earlier stream only for no-tool replies.

## Interruption / supersession

ElevenLabs barge-in stops playback. Completed tool writes are not rolled back. Persisted user turns remain. C.1 frontend stale-response suppression still applies when switching to text.

## Security

Private Agent + signed URL. Browser gets ephemeral access only. Adapter: secret, session HMAC token, ownership, expiry, throttle. Feature is off unless `ELEVENLABS_REALTIME_ENABLED`, `ELEVENLABS_AGENT_ID`, API key, and Custom LLM secret are set. Admin Voice panel shows Realtime Conversation Configured / Not configured.

## Tests

Isolated PHPUnit (Http::fake, FakeAiChatGateway, temp `jarvis-test-*` users). No live ElevenLabs/Telegram.

- legacy Рация store does not call convai signed-url
- owned realtime bind; foreign conversation 404
- auth response has no API key
- adapter resolves session; arbitrary ids ignored; messages persist with `voice_mode=realtime`
- switching chat ends the old realtime session
- ending Voice keeps the Jarvis conversation
- disabled config 503, no outbound HTTP
- confirmation still required; Core task still created
- Telegram services do not take realtime collaborators
- unauthenticated adapter 401

## Build

`php -l` on touched PHP, Pint `--dirty`, `composer validate`, `npm run build`, `git diff --check`. Added JS dependency `@elevenlabs/client` for the Beta SDK only. No PHP dependency change. No migration.

## Production safety

`ELEVENLABS_REALTIME_ENABLED=false` by default. Рация unaffected. No destructive DB operations. Temporary test users cleaned via `CleansTemporaryJarvisRecords` (now also deletes `voice_sessions`).

## Known limitations

- Core still returns a full reply before SSE (correctness over latency).
- Per-user `voice_id` is sent as an Agent TTS override; if the Agent catalog cannot match, Beta may fall back to the Agent voice without changing stored `voice_id`.
- Expressive mode is the Agent/conversational model, not Jarvis emotional tags.
- No JS test runner; PTT/Beta UI is covered by PHP contracts plus the Owner A/B checklist.
- C.1 and C.2 Beta are **IMPLEMENTED / NOT VALIDATED**.

## Owner A/B checklist

A. Рация still works exactly as before (hold / release / interrupt).

B. Same chat: Voice → Диалог Beta → microphone, continuous listening.

C. Say «Привет. Давай обсудим Jarvis.» without holding a button.

D. Natural pause inside a phrase should not cut the turn too early if the ElevenLabs turn model understands continuation.

E. Interrupt Jarvis while speaking → speech stops, new turn accepted. Completed tools are not undone.

F. Create a Task by voice → Core creates it.

G. «Напомни про неё завтра.» → C.1 linking still works.

H. Voice → Text → transcripts/replies already in that conversation.

I. Return to Beta → new realtime session, same Jarvis history.

J. Telegram voice note + voice reply unchanged. No realtime agent there.
