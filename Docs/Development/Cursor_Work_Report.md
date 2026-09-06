# Google Admin Configuration

## Starting HEAD

`5456841dcc85c29dc588e3481265db4ecab24ef6`, equal to `origin/main` at the start of this task.

Working tree was not clean (unrelated WIP from a previous session). That WIP was left unstaged and is not part of this commit.

Branch `main`. No dependency changes.

## Existing Google configuration architecture

Google OAuth client ID, secret, and optional redirect URI were read only from `config/integrations.php` → `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`).

`GoogleOAuthService::isConfigured()` required both ID and secret. Missing env → Admin card **Not configured**, diagnostic **Google client configuration is missing.**, Connect Google disabled.

Connected Google **accounts** (access/refresh tokens) were already encrypted on `integration_accounts`. That path is unchanged.

## Existing secure settings pattern

Singleton Admin settings tables with Laravel `encrypted` casts and DB-over-env precedence:

- `voice_settings.elevenlabs_api_key`
- `web_research_settings.tavily_api_key`
- `ai_provider_settings.api_key`

Admin payloads expose `*_source` (`admin` / `env`) and never return the secret. Empty save keeps the stored secret. Env is not copied into the DB on page load.

## Storage decision

New singleton table `google_oauth_settings` (same pattern as Voice / Web Research). No extra generic secrets system.

Columns: `client_id`, encrypted `client_secret`, `redirect_uri`.

## Encryption

`GoogleOAuthSetting::$casts['client_secret'] = 'encrypted'`. Attribute is `$hidden`. Raw DB ciphertext is not the plaintext secret.

OAuth user tokens remain in `integration_accounts.credentials_encrypted`. This work does not read or rewrite those rows.

## Config precedence

`GoogleOAuthSettingsService`:

1. Admin DB value if present
2. `config('integrations.google.*')` / `.env`
3. Redirect URI default: `rtrim(APP_URL) + /integrations/google/callback`

DB values work at runtime even when Laravel config is cached. `.env` support is not removed. Existing env secrets are not migrated into the DB automatically.

## GoogleOAuthService changes

Injects `GoogleOAuthSettingsService`. `isConfigured()`, `clientId()`, `clientSecret()`, `redirectUri()` go through that single source. No Google HTTP on save or on Integrations page load.

## Admin UI changes

Settings → Integrations → Overview → Google:

- OAuth client vs Account status lines
- Badge: Not configured / Configured / Connected
- Google Configuration form: Client ID, Client Secret (password), Redirect URI (empty uses effective default as hint)
- Save Google configuration → `POST /settings/integrations/google`

Connect Google remains disabled until Client ID + Secret exist.

## Security / permissions

Same `integrations_admin` gate as other integration settings. Ordinary users get 403. Guests redirect to login. Save is throttled `10,1`.

## Secret handling

Client Secret is never in Inertia props, HTML, logs, flash, or this report. UI shows `••••••••••••` + “Secret saved” or “Using deployment configuration”. Blank secret on Save does not clear storage. No Remove-secret action (replace-only).

## Validation

Client ID: nullable string, max 255. Client Secret: nullable, min 8 if present, max 512. Redirect URI: nullable, max 2048, https required; http only for localhost / 127.0.0.1. No remote Google validation on Save.

## Backward compatibility

`.env` fallback kept. Existing Google OAuth connect/callback/token refresh unchanged aside from where client credentials are read.

## Files changed

Model, migration, settings service, settings controller, OAuth service, Google provider status, Integrations payload/UI, routes, docs, tests (authored, not executed).

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty --format agent`, `composer validate`, `npm run build`, `git diff --check`, `php artisan route:list` (google settings route), `php artisan migrate:status` / `migrate --force` for the new table.

## Tests authored but NOT executed

`tests/Feature/GoogleOAuthSettingsTest.php` plus restore helpers in existing Google OAuth/Calendar/Gmail tests so config() fallback still works if the suite is run later. **Not run on this production server.**

## Manual validation steps

Owner only, no Cursor Connect:

1. Admin → Settings → Integrations → Google
2. Enter Client ID and Client Secret
3. Save Google configuration
4. Reload
5. Secret not displayed (•••• / Secret saved)
6. Status Configured
7. Connect Google active

READY FOR OWNER VALIDATION. Not MANUAL PASS. Live Google OAuth was not started.

## Production safety

No Connect Google. No Gmail/Calendar requests. No edits to `integration_accounts` tokens. Unrelated dirty WIP was not committed.
