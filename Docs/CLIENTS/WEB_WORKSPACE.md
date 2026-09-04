# Owner Web Workspace

**Status.** IMPLEMENTED / PARTIAL MANUAL PASS (M22 + M22.1 + M22.2 + M22.3). M23 Voice Runtime Foundation is IMPLEMENTED / NOT VALIDATED (no live STT/TTS). Owner production confirmation 2026-09-04 covers image upload, Gemini vision, text Storage upload/read, and Gemini Google Search only — not the whole Workspace milestone and not Voice.

Owner-facing Personal Workspace. This is **not** the Admin Panel and **not** the User Cabinet (`/cabinet`).

Workspace is part of `Owiiiii1/JARVIS`: Laravel + Inertia/React, one deployment with Core.

---

## Product role

| Surface | Route | For |
| --- | --- | --- |
| Admin Panel | `/dashboard` | technical management: users, AI providers, integrations, Telegram groups, diagnostics |
| Personal Workspace | `/jarvis` | Owner talking to Jarvis |
| User Cabinet | `/cabinet` | `role=user` space |

Owner default landing after ordinary login is `/jarvis`. Admin remains one click from Workspace (`Admin`) and has a reciprocal **Open Jarvis**.

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

- Guest → login.
- `role=user` → redirect to `/cabinet`.
- Owner identity is enough. Workspace does **not** require `integrations_admin` just to open.

No hardcoded owner id.

Owner `/cabinet` redirects to `/jarvis` (conversation show maps to the same id). Normal user Cabinet is unchanged.

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

## Voice (M23 runtime client)

Text / Voice toggle keeps the selected conversation. Voice Mode is `VoiceSession` (not a placeholder): Start Voice, End, Mute, session state, microphone after user gesture, transcript, assistant text, basic playback, CSS orb by state.

Switching Voice → Text ends the active `voice_session` and shows the same message thread (STT/assistant rows are ordinary `messages`).

**Not in M23:** Three.js/GLSL Orb (M24), telephony, live STT/TTS validation.

See [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) and [CLIENTS/VOICE_UI.md](VOICE_UI.md).

---

## Payload

Initial Inertia props are bounded: safe user profile, compact conversation list, selected chat, recent messages, compact projects/reminders/integrations/memory counts, General Prompt text.

No credentials, system prompts, tool logs, or raw group archive.

---

## Out of scope (still)

- Public versioned Client API ([CLIENT_API.md](CLIENT_API.md))
- Three.js Orb (M24)
- Workspace-specific chat tables (attachments live in Core `message_attachments`; persistent files in `stored_files`)
- Telegram photo ingestion (same attachment table later)
- Streaming, delete-chat
- Historical image byte replay into later turns
- Permanent screenshot library / “save image to Storage”
- ContextBudgetManager (done in M22.3)
