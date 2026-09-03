# Cursor work report — Web Cabinet Chat

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `6fdaa772ce9031219b1da6a6f7ce4d7e523acf5a` (`feat: add role-based AI conversation runtime`) |
| Commit message | `feat: add web cabinet conversations` |
| Working tree before start | clean |

## Routes

Authenticated active user:

| Method | Path | Name |
| --- | --- | --- |
| GET | `/cabinet` | `cabinet.index` → redirect to default/`Основной` chat |
| GET | `/cabinet/chats/{conversation}` | `cabinet.chats.show` |
| POST | `/cabinet/chats` | `cabinet.chats.store` |
| PATCH | `/cabinet/chats/{conversation}` | `cabinet.chats.update` |
| GET | `/cabinet/chats/{conversation}/messages` | `cabinet.chats.messages.index` (older page) |
| POST | `/cabinet/chats/{conversation}/messages` | `cabinet.chats.messages.store` |
| GET/PATCH | `/cabinet/ai-settings` | unchanged General Prompt |

## Controllers / services

- `CabinetController` — ensure default conversation, redirect
- `CabinetChatController` — show, create `Новый чат`, rename, send, load older
- `ConversationTurnService::handleUserMessage(User, Conversation, text, ChannelContext)`
- `ChannelContext` — `channel`, `channel_message_id`, `occurred_at`, metadata
- `MessageHistoryService` — last 80 visible messages + `has_more`
- `ConversationService::rename` / `ensureOwned` / `NEW_CHAT_TITLE`

## ConversationTurnService

Channel-neutral core:

1. ownership check (`conversation.user_id === user.id`)
2. persist inbound
3. if duplicate `channel_message_id` → return existing, no second AI call
4. `ConversationAiService::completeUserTurn`
5. result: inbound, optional assistant, optional error

No Nutgram/Inertia types. Default User Conversation AI for `role=user`.

## Telegram refactor

`TelegramUpdateHandler` no longer persists/calls AI itself for paired text. It normalizes Telegram inbound and calls `ConversationTurnService`, then sends the reply. Pairing greeting still uses `ConversationAiService::greetAfterPairing`.

## Cabinet UX

- Left sidebar: conversation list (`last_activity_at DESC`), **Новый чат**, AI Settings, logout
- Main: title + rename, history bubbles (user right, assistant left), composer
- Enter sends, Shift+Enter newline, send disabled while pending
- Optimistic user bubble
- AI error as system-style banner; no fake assistant row
- Load older
- Times shown in `users.timezone`; DB remains UTC
- Responsive: overlay sidebar on mobile

M3 “Сообщение сохранено…” placeholders are hidden from the dialogue. Technical AI failure rows can show as error bubbles.

## Web idempotency

Frontend sends `client_message_id` UUID. Stored as `messages.channel_message_id` with `channel=web`. Unique `(channel, conversation_id, channel_message_id)`. Retry/double-click returns `duplicate: true` and does not create a second inbound or AI turn.

## Ownership protection

`ConversationService::ensureOwned` → 404. Turn service throws if user_id mismatch. User A cannot read/send/rename User B chats. User still 403 on admin `/settings`, `/dashboard`, AI provider config.

## Tests

PHPUnit: 74 passed, 1 skipped. Production DB. Temporary `jarvis-test-*@invalid.local` users cleaned by id. `FakeAiChatGateway` / no billable calls.

Covered: own chats; foreign 404; create; rename own / not foreign; web persist `channel=web`; AI assistant persist; `user_conversation`; idempotency; Telegram+web same conversation; General Prompt in context; AI failure no assistant; admin routes forbidden.

## Build

`npm run build` after frontend changes.

## Production counts (after this work)

| Item | Count |
| --- | --- |
| users | 1 |
| channel_identities | 1 (owner pairing kept) |
| conversations | 2 |
| messages | 8 |

Gemini provider is connected; role configs enabled. No pairing unlinked. No chats/messages deleted.

## Smoke status

Automated: green. Manual user web-only smoke is for the owner after creating a temporary `role=user` in Admin (login → `/cabinet` → `Основной` → Новый чат → send). Cross-channel Telegram smoke optional if a second Telegram account is available.

## Known issues

- Owner is not auto-sent to Cabinet (login still goes to Admin). Visiting `/cabinet` as owner is allowed.
- No streaming.
- No chat delete.
- Cabinet has no full profile/timezone editor (timezone already on user; times use it).
- Load older is JSON; first page is 80 messages.

## Remaining next milestone

Users Admin / User Card (plan Milestone 7), then reminders, groups, tools. Cabinet chat and Telegram already share one catalog and engine.
