# Client API

**Status.** DOCUMENTED ONLY. Not implemented as a public versioned API.

Telegram, Web Workspace, Desktop, and Mobile are adapters/clients. None of them implement Core locally.

Cabinet today talks to Laravel in-process (Inertia + session). That is an implementation detail, not a second engine. Desktop/Mobile require a versioned HTTP/realtime API. Workspace may keep same-origin session first and still obey this contract.

Canonical protocol lives in `Owiiiii1/JARVIS`. Client repos keep a reference only.

---

## Clients never implement locally

- Memory Engine
- Tool execution
- Google / Gmail / Calendar / GitHub credentials
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
| messages / turns | paged history; send turn → Conversation Engine; optional current-turn image attachments |
| attachments | private preview/view of owned `message_attachments`; Desktop/Mobile reuse the same entity |
| streaming | token / typing / speaking events (`TBD` transport) |
| voice sessions | open/close on a `conversation_id`; audio in/out; barge-in |
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

Realtime transport: SSE / WebSocket / WebRTC — `TBD` by practical test. See [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md).

---

## Errors

Safe codes only. No provider raw bodies, tokens, or email dumps in API errors.

---

## Relation to existing surfaces

| Client | Today | Target |
| --- | --- | --- |
| Telegram | in-process adapter | stays adapter |
| User Cabinet | Inertia session | stays User Space web |
| Owner Admin | Inertia session | stays technical admin |
| Owner Workspace | Inertia session `/jarvis` (M22) | Inertia now; Client API later for native clients |
| Desktop / Mobile | not built | Client API only |
