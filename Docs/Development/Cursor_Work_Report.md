# Cursor Work Report — M25U.1 Shared Personal Workspace + Full User Chat

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**Public URL:** https://jarvis.owlsolutions.net  
**GitHub:** https://github.com/Owiiiii1/JARVIS.git  
**Branch:** `main`

---

## Before

Origin/main HEAD before this work:

`bdb4283040c4b9bec31da417bbc6835525e29c43`  
`feat: add audio reactive Jarvis voice orb`

Uncommitted M24 visual polish (transparent Orb, scale/lift) was on the working tree and is included.

---

## Pre-refactor Cabinet vs Owner

Cabinet (`/cabinet`, `Cabinet/Chat.jsx`) was a separate, simpler messenger: text-only composer, no images/files, no Voice Orb, no web-research UI, General Prompt on `/cabinet/ai-settings`.

Owner Workspace (`/jarvis`, `Jarvis/Workspace.jsx`) had the full composer, attachments, Storage, Voice, context panel, Admin.

Both already used `PersonalChatSurfaceService` + `ConversationTurnService`. Cabinet did not expose the same frontend.

---

## Shared frontend

```
resources/js/personal-workspace/
  PersonalWorkspace.jsx   shared Workspace UI
  named.js                surface-prefixed Ziggy routes (jarvis.* / chat.*)
resources/js/Pages/Jarvis/Workspace.jsx   re-export
resources/js/Pages/Chat/Workspace.jsx     re-export
```

One composer, one `SafeMarkdown` renderer, one `VoiceSession` + `JarvisVoiceOrb`. Owner chrome is capability-gated (`ownerContext`, `admin`, `storagePage`, `integrations`).

---

## Canonical routes

| Role | Landing | Chat |
| --- | --- | --- |
| owner | `/jarvis` | `/jarvis/chats/{id}` |
| user | `/chat` | `/chat/chats/{id}` |

Login: one form. Owner → `/jarvis`. User → `/chat`. No register routes.

Compatibility: `/cabinet`, `/cabinet/chats/{id}`, `/cabinet/ai-settings` GET → `/chat` (owner → `/jarvis`). Owner `/chat` → `/jarvis`. User `/jarvis` → `/chat`.

Voice, attachments, confirmations, General Prompt, profile PATCH are registered under both prefixes; same controllers/services. Storage library remains `/jarvis/storage` (owner middleware).

---

## Capabilities before / after (role=user)

**Before:** chat, memory, telegram_dm, reminders, cabinet, profile.

**After:** previous plus `personal_workspace`, `web_research`, `voice`, `storage` (read/search tools and chat file persistence).

Still denied: projects, telegram_groups, group_analysis, gmail, google_calendar, github, integrations_admin, users_admin, impersonation, system_ai_settings, Storage page, `delete_storage_file`.

---

## User tool allowlist (Tool Registry `isAvailable`)

Required/available:

- `create_reminder`
- `search_conversation_history`
- `list_storage_files`, `search_storage_files`, `get_storage_file`, `search_storage_file_contents`, `read_storage_file_chunks`
- `search_web`, `fetch_web_page`
- `confirm_tool_action` / `cancel_tool_action` when a pending confirmation exists

Must not receive: `get_project_context`, `search_group_knowledge`, Google Calendar/Gmail, GitHub, `delete_storage_file`.

---

## Ownership (static)

- Conversations: `PersonalChatSurfaceService::ensureOwned` / `ConversationService::ensureOwned`.
- Attachments: `ChatAttachmentAccessService::owned` (attachment → message → conversation.user_id → auth user).
- StoredFile: `StoredFileService` queries `where user_id`.
- VoiceSession: `VoiceRuntimeService::ownedSession` checks session.user_id and conversation.user_id; capability `voice` required to start.
- General Prompt: `updateOrCreate` on authenticated `user_id` only.

No new tables. No migration.

---

## General Prompt / AI

Each user edits own `user_ai_settings.general_prompt` in the Workspace settings drawer. Owner AI is never a fallback (`AiConfigurationResolver::resolveConversation`).

---

## Owner behavior preserved

`/jarvis` still has projects/context, integrations status, Admin, Storage, Voice, attachments, web research, General Prompt. Same Inertia page name `Jarvis/Workspace`.

---

## Build / static

- `php artisan route:list` — `/chat` and `/jarvis` workspace routes present; no `/chat/storage`.
- `vendor/bin/pint --dirty`
- `npm run build` — pass. Shared `PersonalWorkspace` ~42 kB; Voice/Three.js still lazy ~559 kB.
- `composer dump-autoload` if needed

**TESTS NOT RUN.** No PHPUnit. **NO LIVE AI / WEB SEARCH / VOICE.**

---

## Known limitations

- Per-user total Storage quota not productized.
- Speech providers may still be unconfigured (same clean notice).
- Cabinet POST message endpoint remains text-only compatibility; canonical user send is `/chat/.../messages`.
- User Administration isolation campaign is M25U.2.

---

## Next

M25U.2 User Administration + Isolation Validation.
