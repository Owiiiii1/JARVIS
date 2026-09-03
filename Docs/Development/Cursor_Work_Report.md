# Cursor work report — Milestone 3

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `22ec963238e9facbb8e1c2f87945742ba2202633` (`feat: add Telegram user pairing`) |
| Commit message | `feat: add conversations and Telegram chat selector` |
| Working tree before start | clean |

## Backups (pre-migration)

| Item | Value |
| --- | --- |
| Path | `/var/www/jarvis/storage/backups/m3_users_channel_identities_20260903_183310.sql` |
| Scope | `users` + `channel_identities` schema+data |
| Committed to Git | **no** |

Owner pairing was already present (`channel_identities` count = 1). Backup taken; pairing not unlinked.

## Migrations

1. `2026_09_03_193000_create_conversations_table`
2. `2026_09_03_193100_create_messages_table`
3. `2026_09_03_193200_add_active_conversation_fk_to_channel_identities`

Command: `php artisan migrate --force`

## Schema

### conversations

`id`, `user_id` FK, `kind` (`personal`), `title`, `status`, `last_activity_at`, timestamps. Indexes on kind, status, last_activity_at (user_id via FK).

### messages

`id`, `conversation_id` FK, `user_id` FK, `role` (`user`/`assistant`/`system`), `channel` (`telegram`/`web`), `body`, `message_type` (`text`/`system`/`unsupported`), `channel_message_id`, `metadata`, `occurred_at`, timestamps.

Idempotency unique: `(channel, conversation_id, channel_message_id)` — equivalent to requested channel+external id, because Telegram `message_id` is unique per chat, not globally.

### channel_identities.active_conversation_id

Now a real FK → `conversations.id`, `ON DELETE SET NULL`. Same-user ownership enforced in `ConversationService`.

## ConversationService

- `createPersonal`, `listForUser` (limit 20 in Telegram, 50 in Cabinet)
- `getOrCreateDefault` → title `Основной`, created once
- `ensureActiveConversation` / `setActiveConversation` with ownership check
- Title normalize: trim, collapse whitespace, 1–120 chars; duplicate titles allowed

## Message persistence

`MessagePersistenceService`: `persistInbound` / `persistOutbound` / `persistSystem`.

Paired ordinary text:

1. inbound `role=user`, `channel=telegram`, `message_type=text`
2. placeholder reply persisted as `role=system`, `message_type=system` (not assistant / not future AI dialogue)
3. `conversations.last_activity_at` updated

Retry of the same Telegram message id returns the existing inbound and does **not** create another system row.

## Telegram state

Lightweight persistent state in `channel_identities.metadata.awaiting` (`new_chat_title`), via `TelegramIdentityState`. Not in-memory, not PHP session.

Cancel: button/text `Отмена` or `/cancel`.

## Chat selector UX

Persistent reply keyboard: `Чаты` / `Новый чат` / `Текущий чат`.

- `/start` (paired): ensure `Основной`, `Jarvis подключён. Текущий чат: «…».` + keyboard
- `Чаты`: inline list of owned personal chats
- select callback `c:{id}` → ownership check → `Выбран чат «…».`
- `Новый чат` → ask title → create + activate → `Создан и выбран чат «…».`
- `Текущий чат` → current title

Menu/commands/callbacks are **not** stored as semantic user messages.

## Cabinet / admin

- `/cabinet` lists the same conversation catalog (title + last activity). No message input yet.
- Settings → Users: `chats_count` / `messages_count` via `withCount` (no N+1).

## Tests (production-safe)

No destructive traits. Temporary `jarvis-test-*@invalid.local`; cleanup reverse-order in `finally`. Owner identity not mutated.

| File | Coverage |
| --- | --- |
| `tests/Unit/ConversationServiceTest.php` | create, default once, ownership, list isolation, set active |
| `tests/Unit/MessagePersistenceServiceTest.php` | inbound + idempotent channel message id |
| `tests/Feature/ConversationsCoreTest.php` | schema, `/start` default once, menu not persisted, new chat state, select vs foreign chat, persist+idempotent webhook, cabinet visibility |

```
php artisan test — 53 passed, 1 skipped (owner pairing already exists in prod)
```

After suite: users=1, identities=1, conversations=0, messages=0, no leftover test users.

## Production counts

| Check | Before migrate | After tests |
| --- | --- | --- |
| users | 1 | 1 |
| channel_identities | 1 (owner paired) | 1 (unchanged) |
| conversations | n/a | 0 (owner has no chats until first `/start`) |
| messages | n/a | 0 |
| AI settings | 3 | 3 |
| Telegram bot | connected, webhook set | unchanged; token not modified |

## Build

`npm run build` — success (see command output in this session).

## Manual smoke status

**Awaiting user.** Owner pairing must stay. Please in `@owl_jarvis_bot`:

1. `/start` → current chat `Основной` + keyboard
2. `Чаты` → see `Основной`
3. `Новый чат` → name e.g. `Тест`
4. `Создан и выбран чат «Тест».`
5. send `Привет` → saved in `Тест`
6. select `Основной` → `Выбран чат «Основной».`

Do **not** delete owner history after smoke.

## Known issues / deviations

- Conversation `kind` is `personal` (milestone spec). Docs previously said `direct`; DATABASE.md updated.
- Unique inbound key is `(channel, conversation_id, channel_message_id)`, not global `(channel, channel_message_id)`.
- Outbound Bot API errors are swallowed in the handler so webhook ACK stays successful (tests cannot use real Telegram chats).
- Chat Selector delivered with M3; IMPLEMENTATION_PLAN Milestone 6 marked completed.

## Remaining for Milestone 4

- Three AI configs (Owner Conversation / Owner Analysis / Default User Conversation)
- `AiProviderClient` chat/complete
- After pairing / first DM: Conversation AI greeting in `Основной`
- Replace system placeholder with real assistant replies
- No LLM in Nutgram handlers

## Not changed

- Bot token / webhook secret
- Owner pairing row
- AI runtime
