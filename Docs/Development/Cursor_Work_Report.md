# Cursor work report — Milestone 1

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `fe8888028b60cd08b9375702148344ea69e56516` (`docs: record Milestone 0 after HEAD`) |
| Commit message | `feat: add Jarvis owner and user identity foundation` |
| Working tree before start | clean |

## Users backup (pre-migration)

| Item | Value |
| --- | --- |
| Path | `/var/www/jarvis/storage/backups/users_pre_milestone1_20260903_180004.sql` |
| Scope | `users` table schema + data only |
| Committed to Git | **no** (under `storage/backups/`, gitignored) |
| Note | `mysqldump` emitted a harmless tablespaces privilege warning; dump file contains 58 lines and was verified present |

## Migration

`2026_09_03_180000_add_identity_fields_to_users_table`

- Added columns: `role`, `access_code`, `status`, `timezone`
- Safe owner promotion when exactly one user exists and `2000` is free
- Enforced non-null `access_code` + unique index
- `down()` drops unique index and the four columns without touching core auth columns

Command: `php artisan migrate --force`

## Production DB before / after

| Check | Before | After |
| --- | --- | --- |
| `users` count | 1 | 1 |
| Owner role | n/a | `owner` |
| Owner access_code | n/a | `2000` |
| Owner status | n/a | `active` |
| Owner timezone | n/a | `Europe/Rome` |
| `ai_provider_settings` rows | 3 | 3 (unchanged) |
| `telegram_bot_settings` | `@owl_jarvis_bot`, connected, webhook set | unchanged |
| Unique index on `access_code` | no | yes |

Existing admin account (id=1) promoted in place. Email not reproduced in this report.

## Capability implementation

- `App\Services\Users\UserCapability` — capability constants
- `App\Services\Users\UserCapabilities` — role → default map; owner `*`, user limited set
- `User::canUseCapability()` delegates to service
- No DB capability rows (code map only, per plan)

## Access code generation

- `App\Services\Users\AccessCodeGenerator`
- 6-digit numeric codes, retry on collision, excludes reserved `2000`
- New users receive auto-generated code on create via Settings → Users
- Owner code `2000` set only by migration / system invariant, not via user form

## Auth routing & authorization

- Login redirect: owner → `/dashboard`, user → `/cabinet`
- Admin routes: middleware stack `web`, `auth`, `user.active`, `owner` → HTTP 403 for non-owner
- Cabinet route `/cabinet`: authenticated active users
- Disabled users blocked at login and session invalidated on next request (`EnsureUserIsActive`)
- Owner cannot be deleted via Users CRUD; second owner cannot be created via CRUD

## Cabinet shell

- Route: `GET /cabinet` (`cabinet.index`)
- Page: `resources/js/Pages/Cabinet/Index.jsx`
- Layout: `resources/js/Layouts/CabinetLayout.jsx`
- Placeholder: “Jarvis Cabinet” + basic user info + logout

## Users admin UI

- `Settings → Users` shows: name, email, role, access_code, status, timezone, created_at
- Create user: auto role `user`, generated code, active, `Europe/Rome`
- Edit: owner can change name, email, status, timezone, password for regular users
- Owner row: role/code/status/timezone read-only in form

## Test strategy (production-safe)

No `RefreshDatabase`, `DatabaseMigrations`, factories without cleanup, or destructive traits.

| Suite | Approach |
| --- | --- |
| `tests/Unit/AccessCodeGeneratorTest.php` | Generator logic, reserved code, format |
| `tests/Unit/UserCapabilitiesTest.php` | Static capability map / denied admin caps |
| `tests/Feature/IdentityAuthorizationTest.php` | Schema assertion; owner access; temp user with marker email `@invalid.local` created/deleted in `finally` |
| `tests/Feature/BaselineTest.php` | Updated to use existing owner |

Temporary test users use email prefix `jarvis-test-*@invalid.local`; deleted only when marker matches.

## Temporary user cleanup

- Feature tests: cleanup in `finally` blocks — confirmed
- Manual smoke script: created id=5, verified, deleted — `users` count returned to 1
- Final state: single owner row only

## Test results

```
php artisan test
29 passed (64 assertions)
```

## Build result

```
npm run build — success (Vite 8.2.2)
New chunks: Cabinet/Index, updated UsersPanel
```

## HTTP smoke

| Request | Result |
| --- | --- |
| `GET /` | 200 |
| `GET /dashboard` (guest) | 302 → login |
| `GET /cabinet` (guest) | 302 → login |
| `POST /telegram/webhook` (no secret) | 403 (unchanged ACK path) |

## Telegram unchanged

- Webhook route and controller not modified
- `TelegramBotManager`, Nutgram handlers, token storage untouched
- DB: `@owl_jarvis_bot`, `is_connected=1`, `is_webhook_set=1`

## AI unchanged

- `AiProviderClient` / manager / settings UI not modified
- DB: 3 provider rows, none active (same as before)

## Problems / deviations

- `mysqldump` tablespaces warning (non-blocking; dump usable)
- Migration initially failed on redundant `use RuntimeException;` under PHP 8.5 strict lint — fixed before successful run
- Temporary user HTTP kernel smoke returned 302 (session not bound); PHPUnit `actingAs` tests are authoritative for authz

## Remaining for Milestone 2

- Nutgram webhook handlers (pairing flow)
- `/start` diagnostic response
- `channel_identities` table + pairing service
- Telegram link/unlink admin minimal UI
- No AI greeting yet (Milestone 4)

## Changed files (summary)

**New:** enums, `AccessCodeGenerator`, `UserCapabilities`, middleware, migration, `CabinetController`, Cabinet pages/layout, identity tests

**Updated:** `User` model, auth login redirect, `LoginRequest`, routes, `UserController`, `SettingsController`, `UsersPanel.jsx`, `bootstrap/app.php`, docs

**Not committed:** `storage/backups/users_pre_milestone1_20260903_180004.sql`
