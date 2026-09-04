# Desktop client

**Status.** DOCUMENTED ONLY. Not implemented.

Thin desktop client to Jarvis Core. Source of truth remains the backend. Desktop does **not** contain Jarvis Core, Memory Engine, Tool execution, or Google credentials.

---

## Repository

Recommended GitHub repo: **`Owiiiii1/JARVIS-Desktop`**

- Developed locally, pushed to GitHub.
- Production Laravel host (`/var/www/jarvis`) does **not** contain a Tauri/Rust build tree.
- Master API / client protocol stays in `Owiiiii1/JARVIS` ([CLIENT_API.md](CLIENT_API.md)).

Desktop repo should have its own README, ARCHITECTURE, DEVELOPMENT, API contract *reference*, and RELEASE notes. Those documents point at this repo; they do not fork Core rules.

---

## Stack

| Layer | Choice |
| --- | --- |
| Shell | Tauri 2 |
| UI | React + TypeScript + Vite |
| Voice viz | Three.js / WebGL / custom GLSL |
| Native | Tauri plugins where appropriate |

---

## Role

Same conversations, same Owner Space, same tools, same memory as Telegram and Web Workspace.

Future capabilities:

- microphone / speakers
- voice chat + live transcript + Orb
- system tray
- global hotkey
- push / native notifications
- launch at startup
- background state
- wake-word later
- optional always-on-top compact assistant
- deep links
- updater

---

## What Desktop must not do

- Local Memory Engine
- Local tool execution
- Direct Google / Gmail / Calendar / GitHub HTTP
- Own LLM provider selection
- Separate voice memory or auto-created voice chats

All integrations go through Jarvis backend tools.

---

## Conversation continuity

`conversation_id` is server-owned. Start in Telegram → continue in Workspace → speak on Desktop → open on Mobile. Voice mode uses the selected conversation.

---

## Auth

`TBD` (token / device session against versioned Client API). Same `user_id`. Access code is not a desktop password.

---

## Releases

Desktop updater and GitHub Releases live in `JARVIS-Desktop`. CI/CD is separate from Laravel deploy.

---

## Out of scope now

- Creating the Tauri project
- Rust/node toolchain on the production server
