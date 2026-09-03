# Cursor work report — Milestone 2

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `45873bdd91faca1475dffeffc6ec3f01bbca39d9` (`feat: add Jarvis owner and user identity foundation`) |
| Commit message | `feat: add Telegram user pairing` |
| Working tree before start | clean |

## Migration

`2026_09_03_190000_create_channel_identities_table`

- Table `channel_identities` with FK `user_id`, Telegram fields, `linked_at`, `last_seen_at`, nullable `active_conversation_id`, `metadata` json
- Unique `(channel, external_user_id)`
- Indexes on `user_id`, `channel`

Command: `php artisan migrate --force`

## channel_identities schema

| Column | Notes |
| --- | --- |
| `user_id` | FK → users, cascade delete |
| `channel` | `telegram` for MVP |
| `external_user_id` | Telegram user id (string) |
| `external_chat_id` | private chat id |
| `username`, `first_name`, `last_name` | nullable, refreshed on updates |
| `linked_at`, `last_seen_at` | timestamps |
| `active_conversation_id` | nullable, reserved for Milestone 3 |
| `metadata` | json nullable |

## Nutgram integration

- Webhook: `POST /telegram/webhook` validates secret, then `TelegramWebhookProcessor` hydrates Update and calls `$bot->processUpdate()`
- `TelegramBotFactory` + `TelegramHandlerRegistrar` register `onMessage` handler
- `TelegramUpdateHandler` — private DM pairing flow only
- `TelegramPairingService` — business logic (no AI, no User auto-create)
- Nutgram 4.50.0 via existing dependency; no second Telegram framework
- Default Nutgram logger = NullLogger (no access codes in debug logs)

## Handlers / pairing behaviour

| Case | Response |
| --- | --- |
| Unknown `/start` (private) | Request access code (RU) |
| Unknown text | Exact access_code lookup |
| Invalid code | Code not found message |
| Disabled user code | Access disabled message |
| Success | Authorization success + connected messages |
| Already paired `/start` | Already authorized + AI coming soon |
| Paired non-text | Unsupported message type |
| Paired text | AI coming soon (no LLM) |
| Group/supergroup | Auth only in private chat hint |
| User already has Telegram | Cannot bind second Telegram account |
| Telegram already linked | Re-entering another code → already authorized |

## Users UI

Settings → Users:

- New column **Telegram**: Connected / Not connected (+ `@username` when present)
- Edit modal: **Disconnect Telegram** (owner-only route, deletes identity only)
- Edit modal: **Regenerate access code** for non-owner users (not `2000`; does not unlink Telegram)

Routes:

- `POST /settings/users/{user}/telegram/unlink`
- `POST /settings/users/{user}/access-code/regenerate`

## Tests (production-safe)

No destructive DB traits. Temporary users `jarvis-test-*@invalid.local`; Telegram test external ids `9000xx` / `9100xx`; cleanup in `finally`.

| File | Coverage |
| --- | --- |
| `tests/Unit/TelegramPairingServiceTest.php` | invalid/disabled/pair/idempotent/one-user-one-telegram/owner service-level (skipped if owner already paired) |
| `tests/Feature/TelegramPairingTest.php` | schema, webhook 403, start no identity, bad code, group no pair, good code creates identity |

```
php artisan test — 41 passed (100 assertions)
```

## Production data before / after

| Check | Before migration | After deploy |
| --- | --- | --- |
| `users` count | 1 | 1 |
| `channel_identities` | n/a (table absent) | 0 |
| `ai_provider_settings` | 3 | 3 (unchanged) |
| `telegram_bot_settings` | connected, webhook set | unchanged (token not modified) |

## Build

`npm run build` — success

## Webhook diagnostics

Via `TelegramBotManager::getWebhookInfo()` (no token logged):

| Field | Value |
| --- | --- |
| URL | `https://jarvis.owlsolutions.net/telegram/webhook` |
| Expected URL | matches |
| Pending updates | 0 |
| Last error | none |

## Manual smoke status

**Awaiting user.** Owner Telegram pairing with code `2000` must be performed manually in `@owl_jarvis_bot`:

1. `/start` → code request
2. Send `2000` → success messages
3. Repeat `/start` → already authorized (no code prompt)

Automated tests intentionally avoid binding production owner Telegram identity. `channel_identities` count = 0 before manual smoke.

## AI unchanged

No changes to `app/Services/Ai/*`, AI settings UI, or provider runtime. Pairing path does not invoke AI services.

## Documentation

- `Docs/IMPLEMENTATION_PLAN.md` — Milestone 2 COMPLETED
- `Docs/CURRENT_STATE.md` — Milestone 2 section
- `Docs/USERS_AND_CABINET.md` — one User ↔ one Telegram identity (MVP)
- `Docs/DECISIONS.md` — ADR-046

## Known issues

- Nutgram uses Guzzle directly; Laravel `Http::fake()` does not intercept outbound Bot API calls in feature tests. Behaviour verified via DB state and unit/service tests.
- Outbound Telegram API errors during webhook handling are reported internally; webhook still returns `{ok:true}` to avoid unnecessary Telegram retries for handled updates.

## Remaining for Milestone 3

- `conversations` + `messages` tables
- Conversation Service; wire `active_conversation_id` FK
- Persist inbound/outbound messages (still no full LLM requirement in M3)
- Channel-neutral DTO for cabinet later

## Changed files (summary)

**New:** migration, `ChannelIdentity` model, Telegram pairing/handlers/factory/processor classes, pairing tests

**Updated:** `TelegramWebhookController`, `TelegramBotManager`, `User` relations, `UserController`, `SettingsController`, `UsersPanel.jsx`, routes, docs

**Not changed:** bot token, webhook secret storage, AI runtime, CRM cleanup from M0/M1
