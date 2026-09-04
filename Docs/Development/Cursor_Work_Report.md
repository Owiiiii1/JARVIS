# Cursor Work Report — M25U.2 User Administration + Isolation Hardening

**Date:** 2026-09-04  
**Repo:** `/var/www/jarvis` (`Owiiiii1/JARVIS`)  
**Commit intent:** `feat: complete user administration and isolation controls`

---

## Starting git

| Item | Value |
| --- | --- |
| `git fetch origin` | done |
| Working tree at start | clean |
| Local HEAD = `origin/main` | `00b54e06ca2df474a6258546cd5d17221f154f77` |
| That commit | `feat: add Gemini speech to text provider` |
| M23.2 Gemini STT already on main | **yes — not reverted, not overwritten** |
| M25U.1 | `291e248693e93db04c8ff1f935ce27ad19535f79` `feat: add shared personal workspace for users` (ancestor of M23.2) |
| Uncommitted leftover / other task | none |

Production DB. No `migrate:fresh`. No truncate. No new migration (status, access_code, sessions already exist). Impersonation is session-only.

---

## Pre-existing user admin state

Already present before this milestone and reused:

- `users.role` (`owner`/`user`), `users.status` (`active`/`disabled`), unique `access_code`, `AccessCodeGenerator` (skips `2000`, collision retry)
- Owner-only `UserController` create/update/unlink/regenerate
- `EnsureUserIsActive`, `LoginRequest` disabled check
- `UserCapabilities` for regular users (chat/memory/telegram_dm/reminders/workspace/profile/web_research/voice/storage)
- Isolation via `ConversationService::ensureOwned`, attachment/file/voice/memory/reminder `user_id` scopes
- No `/register` route; pairing does not create users from unknown codes
- Hard **delete** existed in UI/controller — converted to refuse + UI removed
- Double-hash on create (`Hash::make` + `'password' => 'hashed'` cast) — fixed by assigning plaintext to the cast
- Impersonation and User Card page did **not** exist
- Already-linked Telegram could still hit AI when the user was disabled — blocked

---

## What shipped

### Users catalog + create

Admin → Users is a Jarvis user catalog: name, email, role, status, Telegram yes/no, access code, chats/messages counts (`withCount`), last activity (derived), created at. Owner-only create: name, email, password+confirm, timezone; system `role=user`, `status=active`, generated access code. Redirect to User Card. Password never shown after save.

### User Card

`GET /settings/users/{user}` — Overview / Access / Chats / Memory. Profile (role read-only), usage counts, General Prompt on the same `user_ai_settings` row, read-only chat metadata, memory diagnostics link. Actions: Disable/Enable, Set password, Regenerate Telegram Code, Unlink Telegram, Open as User. No prominent delete. Owner account cannot be disabled/demoted/password-reset/code-regenerated through these endpoints. `DELETE` still exists but returns an error: disable instead.

### Active / disabled

Disabled: generic login failure; sessions for that `user_id` deleted; `user.active` blocks the app; ConversationTurn and Voice start reject inactive users; Telegram already-linked sends system copy, no AI; reminders skip (`user_disabled`). Data kept. Reversible.

### Password reset

Owner: new + confirm, Laravel password rules, hashed cast only. Then `UserSessionInvalidator` deletes `sessions` rows for that `user_id` and clears remember token (database session driver). Other users untouched.

Ordinary user: Personal Workspace settings current + new + confirm (`PUT /chat/settings/password`).

### Access code + Telegram

Regenerate: new unique non-`2000` code; old code cannot pair; **linked Telegram stays**. Unlink: delete that user’s Telegram `channel_identities` only; chats/history kept.

### Impersonation

`ImpersonationService` session keys: original owner id, target id, started_at. Start: Owner-only, target `role=user` and active. Auth becomes the target so `/chat` is that user; `EnsureOwner` blocks Admin. Banner on Admin/Workspace/Cabinet layouts. Exit: restore Owner, redirect `/jarvis`; if Owner missing/inactive → login. Logs: `impersonation_started` / `impersonation_ended` with internal ids only. Writes while impersonating mutate that user’s data (banner states this).

If the impersonated user is disabled mid-session, `EnsureUserIsActive` restores Owner instead of wiping the Owner session.

### Counts / last activity

Catalog: `withCount` conversations/messages, `withMax` conversation `last_activity_at`, eager `telegramIdentity`. Card: additional `withCount` memories/files/reminders/voice. No new `last_activity_at` column.

### Login routing

Owner → `/jarvis`. User → `/chat`. No `intended()` to Admin for users. `/cabinet` still compatibility. No self-registration UI.

---

## Ownership enforcement matrix (static IDOR review)

| Surface | Enforcement class / point |
| --- | --- |
| Conversation routes (integer id, `/chat`, `/cabinet` aliases) | `ConversationService::findOwned` / `ensureOwned`; `PersonalChatSurfaceService::ensureOwned`; `JarvisWorkspaceController` / `CabinetChatController` |
| Messages / history | owned conversation first; `ConversationTurnService` `conversation.user_id`; `MessageHistoryService` via that conversation |
| Attachments preview/download | `JarvisAttachmentController` + `ChatAttachmentAccessService` (attachment → message → conversation → user_id) |
| Stored files (search/metadata/chunks/download) | `StoredFileService::findOwnedByPublicId` / `owned`; `StoredFileSearchService` `where user_id`; no UUID-only auth |
| Memory retrieval / summaries | `PersonalMemoryRetriever`, `MemoryWriter`, `ConversationSummaryService` `user_id`; Owner diagnostics `UserMemoryController` (owner middleware, scoped to that user) |
| Voice session (public_id binding) | `JarvisVoiceController` + `VoiceRuntimeService::ownedSession` (`session.user_id` **and** `conversation.user_id`); `ensureOwned` on start |
| User General Prompt | `JarvisWorkspaceController::updateGeneralPrompt` uses `$request->user()->id` only; admin `UserAdministrationService::updateGeneralPrompt` Owner-only for the bound user |
| User management | `owner` middleware + `UserController::assertUsersAdmin` (`USERS_ADMIN` + `isOwner`) |
| Impersonation start | `ImpersonationService::start` Owner + `IMPERSONATION` capability; target must be `role=user` and active |
| Impersonation stop | restores session Owner only; cannot pick another account |
| Telegram unlink / access code | Owner User Card endpoints; `TelegramPairingService::unlinkTelegram` scoped `user_id`; regenerate does not unlink |
| Reminders | `ReminderService` `user_id`; delivery identity `findTelegramForUser` of that user; disabled skip |
| Web research settings | Admin routes behind `owner`; users share instance provider, execution is their conversation turn |

Default User Conversation AI: `AiConfigurationResolver::resolveConversation` (not Owner Conversation AI).

---

## Migrations / backups

None. No table backups taken because no schema change. Existing unique `access_code`; `users.status`; database `sessions.user_id`.

---

## Static checks (allowed)

- `composer dump-autoload`
- `vendor/bin/pint --dirty`
- `php artisan route:list`
- `php artisan migrate:status`
- `php artisan queue:failed`
- `npm run build`
- static ownership audit (this report)

## Not run (Owner policy)

- `php artisan test` / PHPUnit
- destructive feature tests
- live AI / Gemini / Web Search / ElevenLabs / Telegram / Gmail / Calendar / GitHub
- production User A / User B creation

---

## Manual validation checklist

Prepared in [USER_ADMINISTRATION.md](../USER_ADMINISTRATION.md). **NOT RUN.**

---

## Known limitations

- Hard user delete is refused, not implemented as a guarded cascade.
- `(user_id, channel)` uniqueness for Telegram is application-level; DB unique is `(channel, external_user_id)`.
- Last activity is derived, not a dedicated indexed column.
- Session invalidation after password reset applies to the database session driver.
- USER SPACE is not MANUAL PASS until Owner creates A/B and runs the isolation campaign.
- Live Voice STT/TTS still deferred (M23.2 remains on main).

---

## Next recommended action

Owner manually creates User A and User B from Admin and performs the isolation campaign in USER_ADMINISTRATION.md.

No passwords, access codes, Telegram external ids, private chat text, or API keys are recorded here.
