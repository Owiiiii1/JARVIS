# Personal Web Workspace

**Status.** M25U.1 IMPLEMENTED / NOT VALIDATED (2026-09-04). Automated tests not run. No live AI / Web Search / Voice provider calls.

Owner and ordinary users share **one Personal Workspace product**. Role/capabilities change available features, not the chat implementation.

This is **not** the Admin Panel. `/cabinet` is a compatibility redirect only.

Workspace is part of `Owiiiii1/JARVIS`: Laravel + Inertia/React, one deployment with Core.

---

## Product role

| Surface | Route | For |
| --- | --- | --- |
| Admin Panel | `/dashboard` | technical management: users, AI providers, integrations, Telegram groups, diagnostics |
| Owner Personal Workspace | `/jarvis` | Owner talking to Jarvis + owner context (projects, integrations, Storage link, Admin) |
| User Personal Workspace | `/chat` | `role=user` talking to Jarvis (full chat, no owner chrome) |
| Cabinet (deprecated) | `/cabinet` | redirects to `/chat` (owner → `/jarvis`) |

Owner default landing after ordinary login is `/jarvis`. User landing is `/chat`. Admin remains one click from Owner Workspace (`Admin`) and has a reciprocal **Open Jarvis**.

Frontend: `resources/js/personal-workspace/PersonalWorkspace.jsx`. Inertia pages `Jarvis/Workspace` and `Chat/Workspace` re-export it. UI `capabilities` props are presentation-only; backend ownership/capability checks are authoritative.

---

---

## Manual production validation — 2026-09-04

Owner confirmed in production (automated tests not run):

**PASS:**

- Owner Workspace image upload
- Gemini vision recognition
- persistent text-file upload
- persistent Storage retrieval/read
- Gemini Google Search web research

Not Owner-checked here: screenshot expiry/purge, artifact copy as a separate UX check, Storage library rename/download/delete, `fetch_web_page`, Tavily, ContextBudgetManager, Google Calendar/Gmail combined smoke, GitHub runtime.

---

## Authorization

`/jarvis` requires an authenticated active **owner**. Middleware: `auth`, `user.active`, `owner.workspace`.

`/chat` requires an authenticated active **user**. Middleware: `auth`, `user.active`, `personal.workspace` (owner is redirected to `/jarvis`).

- Guest → login.
- `role=user` on `/jarvis` → redirect to `/chat`.
- `role=owner` on `/chat` → redirect to `/jarvis`.
- Owner identity is enough for `/jarvis`. Workspace does **not** require `integrations_admin` just to open.

No hardcoded owner id.

Owner `/cabinet` redirects to `/jarvis`. User `/cabinet` redirects to `/chat`.

---

## Same Core

Workspace uses the existing Owner Space and engines:

- `conversations` / `messages` (no `workspace_conversations`)
- `ConversationTurnService` / Conversation AI
- Memory Engine
- projects, reminders, group knowledge tools
- Google Calendar / Gmail tools
- GitHub tools (M21)
- Storage tools (M22.2)
- Web Research tools (M22.3: `search_web`, `fetch_web_page`). Provider and limits are Admin-only (M22.3.1). Workspace shows read-only web search status; no technical limit editor.
- `tool_confirmations`

This is **not** a second chat engine and **not** a second owner memory.

Telegram-created personal chats appear in `/jarvis`. New Chat creates a normal personal conversation (`kind=personal`). Default unused visit uses `ConversationService::latestOrDefault()` (existing recent chat, otherwise `Основной`).

Web inbound stays `channel=web` + client UUID idempotency. Channel is transport, not UI branding. There is no `workspace` channel enum.

---

## Routes

| Method | Path | Name |
| --- | --- | --- |
| GET | `/jarvis` | `jarvis.index` → redirect to last/recent personal chat |
| GET | `/jarvis/chats/{conversation}` | `jarvis.chats.show` |
| POST | `/jarvis/chats` | `jarvis.chats.store` |
| PATCH | `/jarvis/chats/{conversation}` | `jarvis.chats.update` |
| POST | `/jarvis/chats/{conversation}/messages` | `jarvis.messages.store` (JSON or multipart `body` + `images[]` + `files[]`) |
| GET | `/jarvis/chats/{conversation}/messages/older` | `jarvis.messages.older` |
| GET | `/jarvis/chats/{conversation}/attachments/{attachment}/preview` | `jarvis.attachments.preview` (auth + ownership; 404 after purge) |
| GET | `/jarvis/chats/{conversation}/attachments/{attachment}` | `jarvis.attachments.show` (auth + ownership; 404 after purge) |
| GET | `/jarvis/storage` | `jarvis.storage.index` owner persistent files |
| POST | `/jarvis/storage` | `jarvis.storage.store` |
| GET | `/jarvis/storage/{file}` | `jarvis.storage.show` (`public_id`) |
| PATCH | `/jarvis/storage/{file}` | `jarvis.storage.update` rename |
| DELETE | `/jarvis/storage/{file}` | `jarvis.storage.destroy` |
| GET | `/jarvis/storage/{file}/download` | `jarvis.storage.download` |
| POST | `/jarvis/confirmations/{confirmation}/confirm` | `jarvis.confirmations.confirm` |
| POST | `/jarvis/confirmations/{confirmation}/cancel` | `jarvis.confirmations.cancel` |
| PATCH | `/jarvis/settings/general-prompt` | `jarvis.settings.prompt.update` |

Controllers authorize owner, resolve owned conversations, render Inertia, validate, and call Core services. Logic is not duplicated in the controller.

Shared application service: `PersonalChatSurfaceService` (Cabinet + Workspace). Turn execution remains `ConversationTurnService`.

---

## Layout

`JarvisWorkspaceLayout` — not Admin, not Cabinet.

- Left: conversations (New Chat, **Storage**, local search, title, last activity, selected, rename)
- Center: thread + sticky composer
- Right / mobile drawer: context (projects, reminders, integrations status including read-only **Web Search · Google / Tavily / Disabled**, memory counts, General Prompt)
- Header: Jarvis, AI status dot, Text / Voice, conversation title, Admin, settings

Desktop-first (1280–2560). Sidebar and context collapse on smaller widths. Composer stays usable on mobile web.

---

## Text chat

Send path: Workspace → `PersonalChatSurfaceService` → `ConversationTurnService` → Conversation AI → tools → persisted assistant message.

Composer: multiline, Enter send, Shift+Enter newline, UUID `client_message_id`, local draft, disabled while in flight. Paperclip accepts screenshots **and** persistent text files. Drag/drop, Ctrl/Cmd+V screenshot (text paste is not hijacked). One turn may include text + images + Storage files. Limits come from `chatAttachments` and `jarvisStorage` Inertia props. Desktop restores composer focus after send; mobile does not force the keyboard. See [STORAGE.md](../STORAGE.md).

Thinking state: `Jarvis is thinking...` (no token streaming in M22). Frontend message `status`: `pending` | `streaming` | `completed` | `failed` so streaming can replace the thinking row later.

Assistant bodies render sanitized Markdown (lists, **code fences with Copy**, **artifact fences with Copy**, tables, http(s) links). Code ≠ artifact. Artifact fence: ` ```artifact Title `. Copy uses raw text. No raw HTML execution. No internal system prompts. No raw tool JSON.

User history: ephemeral screenshot thumbnails (“Temporary image · expires in ~24h”); after purge a “Screenshot expired” card with visual summary. Persistent files show “Saved to Storage”. Click screenshot opens a session-auth lightbox. No public attachment URLs.

Empty chat: «Чем займёмся?» + suggestion chips that send ordinary user text.

---

## Confirmations

Workspace Confirm / Cancel call `ToolConfirmationService` through the same turn path (`да` / `отмена`) so Gmail send, Calendar destructive, and GitHub writes keep existing confirmation policy.

Cards show action summary, provider/tool family, safe preview (Gmail recipients/subject/body; Calendar/GitHub bounded argument preview), expiry when present. Encrypted args are not exposed.

---

## Personal vs technical settings

Workspace (personal):

- General Prompt (`user_ai_settings`)
- timezone display
- voice preferences placeholder
- integrations status + deep link to Admin

Admin (technical):

- provider API keys
- model selection
- OAuth connect/disconnect
- workers / webhook / system integrations

Workspace does not reproduce OAuth forms or AI provider settings.

---

## Voice (M23 runtime + M23.2 Gemini STT + M24 Orb)

Text / Voice toggle keeps the selected conversation. `VoiceSession` owns session HTTP. `JarvisVoiceOrb` renders a provider-neutral Three.js energy sphere from `VoiceVisualizationState`. Microphone after user gesture. Local Web Audio analyser drives listening deformation even when STT is not configured. Status **Speech providers not configured** is a clean notice, not a crash; it clears through existing status/error props when Admin STT/TTS are configured. Ordinary users do not see a Gemini vendor label.

Switching Voice → Text ends the active `voice_session` and shows the same message thread.

Demo visualization: `?voice_demo=1` or `VITE_VOICE_DEMO_MODE`. Not live TTS.

See [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) and [CLIENTS/VOICE_UI.md](VOICE_UI.md).

---

## Payload

Initial Inertia props are bounded: safe user profile, compact conversation list, selected chat, recent messages, compact projects/reminders/integrations/memory counts, General Prompt text.

No credentials, system prompts, tool logs, or raw group archive.

---

## Out of scope (still)

- Public versioned Client API ([CLIENT_API.md](CLIENT_API.md))
- Telephony / Twilio
- Workspace-specific chat tables (attachments live in Core `message_attachments`; persistent files in `stored_files`)
- Telegram photo ingestion (same attachment table later)
- Streaming, delete-chat
- Historical image byte replay into later turns
- Permanent screenshot library / “save image to Storage”
- ContextBudgetManager (done in M22.3)
