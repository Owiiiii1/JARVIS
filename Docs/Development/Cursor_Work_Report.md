# Workspace Conversation Delete

## Starting HEAD

- Baseline: `898e6f7b6ed00f4ad499e331cce2e8ba3d82811c` `fix: stop password autofill from hijacking sidebar chat search`
- Working tree was clean; `HEAD == origin/main` before work.
- No migrations. Production DB `jarvis` was not migrated, refreshed, or mass-updated. No live provider calls.

## Conversation relations audit

No SoftDeletes in the project. Personal Workspace lists `kind=personal` only (`ConversationService::findOwned` / `listForUser`).

FK snapshot used for the lifecycle:

| Relation | On conversation delete |
| --- | --- |
| `messages` | cascade — child chat data |
| `message_attachments` | cascade via messages; ephemeral bytes then deleted from disk |
| `message_stored_files` | cascade via messages; **`stored_files` rows stay** |
| `conversation_summaries`, `memory_analysis_runs` | cascade — child of this chat |
| `tool_confirmations`, `voice_sessions` | cascade — session/child of this chat |
| `project_conversations` | cascade pivot; **project stays** (also explicit detach) |
| `tasks.source_conversation_id` / `source_message_id` | nullOnDelete; also explicit null |
| `reminders.source_conversation_id` / `source_message_id` | nullOnDelete; also explicit null |
| `memory_sources.conversation_id` / `message_id` / `summary_id` | nullOnDelete; also explicit null; **memory row stays** |
| `tool_execution_logs.conversation_id` | nullOnDelete — audit log stays |
| `channel_identities.active_conversation_id` | nullOnDelete |
| `user_assistant_profiles.onboarding_conversation_id` | nullOnDelete |
| `telegram_groups.conversation_id` | cascade — **not reachable**: Workspace delete refuses `kind=group` (404) |
| `jarvis_notifications` | no FK; `action_url` that pointed at `/jarvis/chats/{id}` or `/chat/chats/{id}` is rewritten to workspace root (query preserved). Notification history is not deleted. |

## Delete semantics

Hard delete of the owned personal conversation after a DB transaction:

1. Null independent source refs (tasks, reminders, memory_sources).
2. Rewire notification links.
3. Detach `project_conversations`.
4. `$conversation->delete()` (cascades child chat rows).
5. After commit: delete ephemeral screenshot bytes + thumbnails from disk.

If step 4 fails, the transaction rolls back; disk purge does not run.

## Ownership

`ConversationService::ensureOwned` / `findOwned`: same `user_id` **and** `kind=personal`. Foreign id → 404 (does not confirm existence). Owner role is not a bypass for another user’s chats. Group conversations are 404 on this endpoint.

## Confirmation UX

Sidebar item: always-visible three-dot menu (not hover-only) with Переименовать / Удалить.

Удалить opens a Workspace dialog (not `window.confirm`):

- «Удалить этот чат?»
- «Название» or «Без названия»
- History will be deleted; irreversible
- Отмена / destructive Удалить
- Loading: «Удаление...»; buttons disabled

Cancel only closes the dialog.

## Active conversation behavior

JSON: `{ success, deleted_id, conversation: { id, title, last_activity_at } }` where `conversation` is `latestOrDefault()` after delete (existing most-recent personal chat, or a new `Основной`).

Frontend: remove the id from the local sidebar list without F5. If the deleted id was open, Inertia `router.visit` the returned conversation. URL never stays on a deleted id.

## Messages / attachments

Messages of that conversation are deleted (cascade). No orphans.

Ephemeral `message_attachments` (screenshots): DB row + disk files/thumbnails.

Persistent Storage: `stored_files` survive; only `message_stored_files` links drop.

## Tasks / reminders

Task and Reminder rows remain. `source_conversation_id` and `source_message_id` become null.

## Projects

Project remains. Pivot `project_conversations` is removed.

## Memory / storage behavior

Deleting a chat is not «forget». Durable `memories` stay. Provenance on `memory_sources` is detached. Conversation summaries for that chat are child data and go with the conversation. Persistent Storage files are independent of any one message.

## Routes

- `DELETE /jarvis/chats/{conversation}` → `jarvis.chats.destroy`
- `DELETE /chat/chats/{conversation}` → `chat.chats.destroy`

Same `JarvisWorkspaceController::destroy` → `PersonalChatSurfaceService::deleteChat` → `ConversationService::deletePersonal`.

## Tests

- Feature: guest redirect; own delete; foreign 404; owner cannot delete another user’s chat; group 404; switching away from the open chat.
- Unit service: task/reminder source null + survive; project detach; stored file survive; ephemeral disk purge; memory survive; notification URL fallback; no orphan messages; transaction rollback on forced failure; last chat creates `Основной`.
- Frontend static: menu + confirmation copy; no `window.confirm` / `window.location.reload`; DELETE + sidebar filter + visit next.

## Production safety

No migration (existing FKs already cascade/null as required). No destructive live-DB scripts. No provider calls. Tests use temporary `@invalid.local` users via `CleansTemporaryJarvisRecords`.

## Manual checklist

A. Create a test chat.
B. Overflow menu → Удалить: nothing is deleted yet.
C. Отмена: chat remains.
D. Удалить → confirm: chat disappears without F5.
E. Delete the currently open chat: Workspace switches to another chat (or `Основной`).
F. Chat that created a Task/Reminder: after delete, Task/Reminder remain with source detached.
G. Ordinary user cannot delete someone else’s chat (404).
H. Mobile: three-dot is visible without hover.
