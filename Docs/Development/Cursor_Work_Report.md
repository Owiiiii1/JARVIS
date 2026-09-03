# Cursor work report — Telegram Groups (Milestone 11)

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `4b99f54` (`feat: add owner project containers`) |
| Commit message | `feat: add Telegram groups monitoring` |
| Working tree before start | clean |

## Backup

Before migration: `storage/backups/pre_telegram_groups_20260903_232800.sql` (gitignored). Tables: `users`, `conversations`, `messages`, `channel_identities`, `reminders`, `memories`, `topics`, `projects`, `ai_role_settings`, `conversation_summaries`. Existing personal DM history was not deleted.

## Migrations

`2026_09_03_240000_create_telegram_group_tables` ran with `--force`. `php artisan migrate:status` shows it Ran (batch 11).

## Group schema

`telegram_groups`: unique Telegram chat id, unique `conversation_id`, title/username, `chat_type`, status (`connected` / `restricted` / `left`), nullable IANA `timezone`, `first_seen_at` / `last_seen_at` / `last_message_at`, `message_count` (increment only on newly created rows), `settings` default `{"mode":"persist_only"}`, metadata.

`telegram_group_participants`: unique `(telegram_group_id, telegram_user_id)`. No FK to `users`.

`messages` extra columns: `telegram_group_id`, `sender_external_id`, `sender_username`, `sender_name`, `reply_to_channel_message_id`, `thread_id`, `edited_at`.

`project_groups`: unique `(project_id, telegram_group_id)`, `attached_at`. Relation only; raw group history is not copied into Projects.

## Conversation representation

`conversations.user_id` stays NOT NULL. Group conversations use the Jarvis owner as administrative owner, `kind=group`, and a 1:1 `telegram_groups.conversation_id` row as the real boundary (ADR-056). Cabinet, Telegram DM selector, Conversation AI, personal memory retrieval, history search, and memory jobs filter `kind=personal`.

## Participant model

Telegram senders are `telegram_group_participants`, not Jarvis Users. A participant is created only when Telegram sends a real `from` user. `sender_chat` / anonymous admin stores available name/metadata on the message and does not invent a participant (ADR-059).

## Group update routing

Same queued Telegram webhook path (`ProcessTelegramUpdate` on queue `telegram`). Nutgram now also handles `edited_message` and `my_chat_member`.

- `private` → existing DM pairing / Conversation AI (unchanged)
- `group` / `supergroup` (forum = supergroup + `thread_id`) → Groups subsystem: discover, persist, return
- `channel` → ignored

The previous group pairing hint send was removed. Jarvis does not auto-reply in groups.

## Idempotency

Inbound and outbound uniqueness remains `(channel, conversation_id, channel_message_id)`. Two groups may share the same Telegram `message_id`. Duplicate webhooks do not create a second row and do not increment `message_count`. Discovery uses a unique chat id, a transaction, and unique-constraint recovery.

## Supported message types

Text is stored as body. Photo, document, video, voice, audio, sticker, location, contact, poll, and other types store a row plus bounded metadata (Telegram file ids, caption, mime, name, size when present). Media blobs are not downloaded.

## Edited / reply / thread

`edited_message` updates the existing raw row (`body` / metadata / `edited_at`). Telegram reply id is `reply_to_channel_message_id` (not `parent_message_id`, which stays AI reply linkage). Forum `message_thread_id` is `thread_id`. One Telegram group remains one group conversation.

`my_chat_member`: bot left/kicked → `left`; restricted → `restricted`; member/admin/creator → `connected`. History is not deleted.

## Admin UI

Owner nav **Telegram Groups** (separate from Settings Telegram token). List: title, type, status, message count, first seen, last message, timezone (effective/fallback), persist-only mode, Open. No manual Create. Group page: messenger bubbles, sender, timestamps in effective timezone, bot outbound vs members, type/media placeholders, edited/reply/thread markers, cursor pagination, timezone field, compose. Telegram numeric chat id is visible to Owner in the UI only.

## Outbound service

`GroupMessagingService` → existing `TelegramBotManager::sendTextMessage` (now returns `message_id`) → persist outbound `role=assistant`, `metadata.group_outbound=true`. Failed Telegram calls are not stored as sent; kicked/forbidden/not found → `left`, insufficient rights → `restricted`. Echo of the same Telegram message id merges. Reminder delivery still uses the same adapter.

## Timezone

Nullable group timezone, validated as `DateTimeZone`. Effective timezone = group value or owner timezone. UI shows fallback.

## Privacy mode / manual Telegram prerequisites

Not changed by this milestone (no BotFather, no token/webhook rewrite).

For the bot to receive ordinary group messages (not only commands/mentions/replies to the bot), the Owner must:

1. Add the bot to a Telegram group.
2. Grant admin or the needed read permissions if Telegram requires them.
3. If full history is required: BotFather → `/setprivacy` → Disable.

Until Telegram actually delivers updates, Admin history cannot be complete. This work does not claim full-monitoring is live.

## project_groups status

Implemented. Owner attach/detach on the Project page. `get_project_context` may list attached group title/status. It does not return raw group messages.

## Tests

Production-safe: no destructive DB traits; fake HTTP Telegram; Fake AI gateway; temporary `jarvis-test-*@invalid.local` users; test group chat ids in a reserved synthetic range, cleaned by those ids. Covered: schema; first-update discovery once; race-safe discover; duplicate message id; text + participant refresh; no Conversation AI / memory jobs / personal memory on group inbound; private DM still works; two groups same Telegram message id; non-text + reply + thread; edited update; `my_chat_member` left keeps history; owner vs user authorization; outbound success once / fail not persisted / echo merge; timezone validation/fallback; project attach without raw context; anonymous sender_chat. Existing reminder, memory, project, pairing, and conversation tests remain green.

`php artisan test`: 125 tests, 124 passed, 1 skipped.

## Build

`npm run build` succeeded.

## Production counts (after tests)

| Item | Count |
| --- | --- |
| `telegram_groups` | 0 (none discovered yet; test rows cleaned) |
| group conversations | 0 |
| group messages | 0 |
| participants | 0 |
| `project_groups` | 0 |
| personal conversations | unchanged (3) |

## Worker status

- Telegram worker: crontab `flock` `queue:work database --queue=telegram` — running
- Memory/default worker: user `queue:work database --queue=memory,default` process — running (`jarvis-queue.service` unit inactive, same as before)
- Reminder scheduler: `schedule:run` cron + `jarvis:reminders:dispatch` every minute — listed
- `queue:failed`: empty

No new queue worker was added.

## Webhook diagnostics

Webhook remains set. Pending update count 0. Telegram reported a last error flag from getWebhookInfo (no token/secret/URL/body copied here). Token and webhook secret were not rotated.

## Manual smoke status

Awaiting Owner. Cursor did not create a Telegram group or add the bot. After privacy/rights are set, the Owner should: write from several members (including a reply), confirm Jarvis stays silent, confirm Admin list/chat, send one Admin outbound, confirm a single history row, then send an owner DM and confirm personal AI still replies.

## Known issues

- Full group history depends on Telegram privacy mode and bot rights; M11 only persists updates Telegram sends.
- Channels are not supported.
- Group analysis, mention answering, media download, and revision history of edited text are later (M14+).
- Remaining `my_chat_member` edge cases beyond bot left/kicked/restricted/member/admin are not fully normalized.

## Next milestone

Milestone 14 — Group Analysis (summaries, decisions, tasks, facts; Analysis AI jobs; still no personal-memory bleed).
