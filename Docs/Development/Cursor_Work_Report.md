# Cursor work report — Milestone 0

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `f900093` (`docs: finalize spaces chats reminders projects and group intelligence`) |
| After HEAD | `3ca890d` on `origin/main` |
| Working tree before start | clean, matched `origin/main` |

## Changed files

- `database/migrations/2026_09_03_162500_drop_legacy_crm_tables.php` (new)
- `tests/Feature/BaselineTest.php` (new)
- `phpunit.xml` (removed sqlite `:memory:` overrides; host PHP 8.5 has no `pdo_sqlite`)
- `resources/js/Layouts/AuthLayout.jsx` (removed leftover CRM marketing copy)
- `Docs/IMPLEMENTATION_PLAN.md` (Milestone 0 marked COMPLETED)
- `Docs/CURRENT_STATE.md` (Implementation progress only)
- `Docs/Development/Cursor_Work_Report.md` (this file)

## Deleted files

- `app/Models/Customer.php`
- `app/Models/Service.php`
- `app/Models/Staff.php`
- `app/Models/Order.php`
- `app/Http/Controllers/CustomersController.php`
- `app/Http/Controllers/ServicesController.php`
- `app/Http/Controllers/StaffController.php`
- `app/Http/Controllers/OrdersController.php`
- `resources/js/Pages/Customers/Index.jsx`
- `resources/js/Pages/Services/Index.jsx`
- `resources/js/Pages/Staff/Index.jsx`
- `resources/js/Pages/Orders/Index.jsx`

Old CRM create-migrations were **not** edited.

## Migration

`2026_09_03_162500_drop_legacy_crm_tables`

- `up()`: refuse if any legacy table has rows; drop `order_staff` → `orders` → `customers` / `services` / `staff`.
- `down()`: recreate the original empty CRM schema and FKs.

## DB before / after legacy tables

| Table | Before | After |
| --- | --- | --- |
| `order_staff` | exists, 0 rows | dropped |
| `orders` | exists, 0 rows | dropped |
| `customers` | exists, 0 rows | dropped |
| `services` | exists, 0 rows | dropped |
| `staff` | exists, 0 rows | dropped |
| `users` | 1 row | 1 row (unchanged) |
| `ai_provider_settings` | 3 rows | 3 rows (unchanged) |
| `telegram_bot_settings` | 1 row | 1 row (unchanged) |

`php artisan migrate --force`: `2026_09_03_162500_drop_legacy_crm_tables` batch 3 DONE.

## Tests

```
php artisan test
8 passed, 22 assertions
```

Coverage: guest login 200; guest `/dashboard` redirect; authenticated dashboard/settings/Telegram tab 200; `/customers|/services|/staff|/orders` 404.

Auth tests use the existing admin row. No `RefreshDatabase` / no factory inserts (no sqlite driver; production MySQL must not be wiped).

## Build

`npm run build` — success (Vite 8.2.2). CRM page chunks gone.

## Route verification

32 routes, same surface as before cleanup. No CRM routes. Present: login, dashboard, settings, Telegram settings POSTs, `POST /telegram/webhook`, calendar, logs, AI settings.

Live HTTP:

- `GET /` → 200
- guest `/dashboard`, `/settings` → 302 `/`
- `/customers`, `/orders`, `/staff`, `/services` → 404
- in-process authenticated: `/dashboard`, `/settings`, `/settings?tab=telegram`, `/calendar` → 200

## Telegram baseline

- `nutgram/nutgram` 4.50.0 installed
- `POST /telegram/webhook` unchanged (ACK-only controller)
- settings row present; webhook still marked set/connected
- bot token not read or rewritten; Telegram API not called

## AI baseline

- AI settings UI/routes unchanged
- 3 provider rows still present
- no AI runtime/architecture change

## Problems / deviations

- PHP 8.5 has `pdo_mysql` only. phpunit.xml no longer forces sqlite `:memory:`.
- `custom-admin-kit` vendor stubs still contain CRM presets. Host routes do not load them. Re-publishing the admin preset could restore files; vendor was not modified.
- `storage/app/owl-admin-kit.json` still lists originally published CRM paths (historical kit inventory).
- Login-page AuthLayout copy no longer mentions customers/orders/staff.

## Left for Milestone 1

- `users.role` (`owner` / `user`), `access_code` (owner `2000`), `status`, timezone contract
- capability defaults from role
- web split: owner → Admin Panel; user → Cabinet (not admin)
- do **not** do Telegram pairing, Nutgram handlers, AI chat, conversations, reminders, projects, groups
