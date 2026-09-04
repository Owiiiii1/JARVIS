# Client API

**Status.** DOCUMENTED ONLY. Not implemented as a public versioned API.

Telegram, Web Workspace, Desktop, and Mobile are adapters/clients. None of them implement Core locally.

Web Personal Workspace today talks to Laravel in-process (Inertia + session). That is an implementation detail, not a second engine. Desktop/Mobile require a versioned HTTP/realtime API. Workspace may keep same-origin session first and still obey this contract.

Canonical protocol lives in `Owiiiii1/JARVIS`. Client repos keep a reference only.

---

## Clients never implement locally

- Memory Engine
- Tool execution
- Google / Gmail / Calendar / GitHub credentials
- web search API keys
- group intelligence
- project knowledge assembly
- AI provider selection

All of that stays in Jarvis Core.

---

## Domains (minimum)

| Domain | Responsibility |
| --- | --- |
| auth | login / refresh / device session / whoami / capabilities |
| conversations | list / create / select / rename; same catalog as Telegram |
| messages / turns | paged history; send turn → Conversation Engine; optional current-turn images and Storage files |
| attachments | private preview/view of owned ephemeral `message_attachments`; Desktop/Mobile reuse the same entity |
| storage | owner persistent `stored_files`; upload / list / preview / download / rename / delete; not Admin |
| streaming | token / typing / speaking events (`TBD` transport) |
| voice sessions | open/close on a `conversation_id`; audio in/out; barge-in; same `VoiceRuntimeService` as Web |
| tool confirmation | pending summary/preview; confirm / cancel |
| reminders | list/status if needed; create remains a conversation tool |
| projects | list / context via Core (owner) |
| user / profile / settings | timezone, General Prompt, voice prefs |
| integrations status | connected / permission required — no secrets; GitHub login/scopes without tokens |

Ownership: URL id is not enough. Policy checks `user_id`. ADR-021.

Client does not send platform prompts or pick a vendor. Core resolves Owner Conversation AI or Default User Conversation AI.

---

## Versioning

`TBD` (`/api/v1` or header). Choose when Desktop/Mobile (M25) start. M22 Workspace stays same-origin Inertia session; it does not ship a public Client API.

Idempotency: client send key + existing `channel_message_id` rules. `TBD`.

Realtime transport: SSE / WebSocket / WebRTC — still `TBD` for native streaming. M23 Web Voice uses HTTP JSON (create session, POST utterance blob, poll/GET events). Domain events are transport-neutral. See [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md).

---

## Voice sessions (future versioned Client API)

Desktop/Mobile must reuse `VoiceRuntimeService`. Do not put runtime logic in a Web controller.

Suggested v1 equivalents of current Owner Workspace session routes:

| Method | Web (M23, session auth) | Client API (later) |
| --- | --- | --- |
| POST | `/jarvis/chats/{conversation}/voice/sessions` | `/api/v1/conversations/{id}/voice/sessions` |
| GET | `/jarvis/voice/sessions/{session}` | `/api/v1/voice/sessions/{public_id}` |
| POST | `/jarvis/voice/sessions/{session}/listen` | `/api/v1/voice/sessions/{public_id}/listen` |
| POST | `/jarvis/voice/sessions/{session}/audio` | `/api/v1/voice/sessions/{public_id}/audio` |
| POST | `/jarvis/voice/sessions/{session}/interrupt` | `/api/v1/voice/sessions/{public_id}/interrupt` |
| POST | `/jarvis/voice/sessions/{session}/mute` | `/api/v1/voice/sessions/{public_id}/mute` |
| POST | `/jarvis/voice/sessions/{session}/resume` | `/api/v1/voice/sessions/{public_id}/resume` |
| DELETE | `/jarvis/voice/sessions/{session}` | `/api/v1/voice/sessions/{public_id}` |

Auth: ownership (`user_id` + conversation ownership), never UUID alone. Same safe error codes as [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md).

Clients render Voice UI from `VoiceVisualizationState` (`state`, amplitudes, `frequencyBands`, `connectionState`). Runtime status comes from the session; amplitudes are local Web Audio. See [VOICE_UI.md](VOICE_UI.md).

---

## Errors

Safe codes only. No provider raw bodies, tokens, email dumps, or fetched web page bodies in API errors. Web Research stays server-side tools (`search_web` / `fetch_web_page`); clients never call Tavily. One-turn context size is a Core concern (`ContextBudgetManager`), not a client prompt dump.

---

## Relation to existing surfaces

| Client | Today | Target |
| --- | --- | --- |
| Telegram | in-process adapter | stays adapter |
| User Personal Workspace | Inertia session `/chat` | stays User Space web (`/cabinet` redirect) |
| Owner Admin | Inertia session | stays technical admin |
| Owner Workspace | Inertia session `/jarvis` | Inertia now; Client API later for native clients |
| Desktop / Mobile | not built | Client API only |
