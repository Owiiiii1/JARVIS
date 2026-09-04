# Cursor Work Report — Milestone 22 Owner Web Workspace

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS` (`origin/main`)  
**Public URL:** https://jarvis.owlsolutions.net

Status: **IMPLEMENTED / NOT VALIDATED**. Owner deferred all automated tests and all live AI / Google / GitHub sends.

---

## Before HEAD

`ac1ada4d6f9ac9120f1eaea92f6579228c89a3a4` — `feat: add GitHub integration` (M21). Working tree was clean.

No database migration in this milestone. Production schema unchanged: Workspace is a UI surface over existing `conversations` / `messages` / `tool_confirmations` / `user_ai_settings`.

---

## Routes

| Method | Path | Name |
| --- | --- | --- |
| GET | `/jarvis` | `jarvis.index` |
| GET | `/jarvis/chats/{conversation}` | `jarvis.chats.show` |
| POST | `/jarvis/chats` | `jarvis.chats.store` |
| PATCH | `/jarvis/chats/{conversation}` | `jarvis.chats.update` |
| POST | `/jarvis/chats/{conversation}/messages` | `jarvis.messages.store` |
| GET | `/jarvis/chats/{conversation}/messages/older` | `jarvis.messages.older` |
| POST | `/jarvis/confirmations/{confirmation}/confirm` | `jarvis.confirmations.confirm` |
| POST | `/jarvis/confirmations/{confirmation}/cancel` | `jarvis.confirmations.cancel` |
| PATCH | `/jarvis/settings/general-prompt` | `jarvis.settings.prompt.update` |

`GET /jarvis` redirects to the owner’s latest personal conversation (`ConversationService::latestOrDefault`), otherwise creates the existing `Основной` default once.

---

## Authorization

- Middleware: `auth`, `user.active`, `owner.workspace` (`EnsureOwnerWorkspace`).
- Guest → login.
- `role=user` → `/cabinet`.
- Owner identity is enough; Workspace does not require `integrations_admin`.
- No hardcoded owner id.

Owner hitting `/cabinet*` is redirected by `RedirectOwnerCabinetToWorkspace` (`cabinet.chats.show` keeps the same conversation id). Normal user Cabinet routes are unchanged.

---

## Owner login redirect

Ordinary owner login lands on `/jarvis`. `intended()` still honours an explicit Admin URL. Admin Panel remains at `/dashboard` with **Open Jarvis** in the header, sidebar, and dashboard cards. Workspace header has a secondary **Admin** link.

---

## Layout / components

- `JarvisWorkspaceLayout` — dark cinematic shell, not Admin, not Cabinet.
- `Pages/Jarvis/Workspace.jsx` — sidebar, thread, composer, context panel, settings/prompt modals.
- `SafeMarkdown` — lists, fenced code + copy, tables, http(s) links; no raw HTML.
- `VoiceModePlaceholder` + `OrbPlaceholder` — CSS only; future `<VoiceSession conversationId>` boundary documented.
- Admin `Open Jarvis`; Dashboard first card is Workspace.

---

## Conversation reuse

Same `conversations` and `messages` as Telegram. No `workspace_conversations`. New Chat = `ConversationService::createPersonal`. Channel stays `web` + UUID `client_message_id`.

---

## Shared services

`PersonalChatSurfaceService` is the shared application layer for Cabinet and Workspace: sidebar, page, create, rename, send turn, confirmation resolve. `ConversationTurnService` is not rewritten.

`OwnerWorkspaceContextService` builds bounded secondary-panel summaries from `ProjectService`, `ReminderService`, `IntegrationRegistry`, and memory/topic counts. No Admin controllers.

Cabinet `CabinetChatController` now calls the shared surface service. User-facing Cabinet behavior stays the same catalog/turn path.

---

## Sidebar / chat / context

- Left: New Chat, local search, titles, last activity, selected state, rename.
- Center: recent messages, load older, sticky composer (Enter send, Shift+Enter newline, thinking state, local draft).
- Empty chat: «Чем займёмся?» + suggestion chips that send normal user text.
- Right / mobile drawer: projects (list + attached + Admin deep link), upcoming reminders read-only, integrations compact status (Google identity/Calendar/Gmail, GitHub, Telegram, ElevenLabs placeholder), memory counts + General Prompt.

---

## Confirmations

Confirm / Cancel hit Workspace confirmation routes, then the same turn path (`да` / `отмена`) and `ToolConfirmationService`. Safe previews: Gmail recipients/subject/body; Calendar/GitHub bounded argument fields. Encrypted args are not sent to the client.

---

## General Prompt

Editable from Workspace modal/drawer via `user_ai_settings` (`jarvis.settings.prompt.update`). Provider/model stay Admin-only.

---

## Voice placeholder

Text / Voice toggle. Voice panel: CSS sphere + glow + idle breathing (`prefers-reduced-motion` disables animation). No microphone, WebRTC, ElevenLabs, STT/TTS, or Three.js.

---

## Responsive

Desktop: conversation-first, sidebar ~260–320px class width, context panel collapsible. Mobile web: conversation list overlay, context drawer, sticky composer.

---

## Verification (non-test)

Ran only:

- `npm run build`
- `php artisan route:list --name=jarvis`
- `php artisan migrate:status`
- `php artisan queue:failed`
- `php artisan schedule:list`
- `vendor/bin/pint --dirty`

**TESTS NOT RUN — Owner deferred.**  
**NO LIVE AI / Google / GitHub.**  
No conversational send. Production DB tables unchanged.

---

## Known issues

- Workspace conversational UX is unvalidated (no live turn).
- Google / GitHub integrations may still be disconnected in production; status panel will show that honestly.
- Voice is a layout placeholder only (M23).
- Public Client API is not implemented (still later).
- Delete-chat is not offered (Core has no delete).

---

## Next

**M23 Voice Runtime Foundation** — STT/TTS/realtime abstraction on the selected `conversation_id`. Replace `VoiceModePlaceholder` with `<VoiceSession />`. No Three.js Orb yet (M24).
