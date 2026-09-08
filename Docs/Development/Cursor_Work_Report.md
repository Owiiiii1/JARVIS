# Recurring Gmail Monitoring

## Starting HEAD

`460eeb2ba7f6457aabb0c9d9f8373fb05fd8286a` (`fix: synchronize confirmation lifecycle across workspace modes`).

Branch `main`. No dependency changes.

## Live user gap

Owner said: “Проверяй каждое утро почту и сообщай мне, что нового пришло.”

Jarvis created a Reminder to check mail, then claimed it could not check Gmail itself. That is a live product gap: Gmail watcher infrastructure already existed.

## Existing Gmail watcher capability

Already in code before this change:

- `GmailWatcherSource` / `LiveGmailWatcherClient`
- trigger `gmail_message`, condition `new_item`
- first evaluation baseline (`cursor.baseline_established` + `seen`)
- fingerprint anti-duplicate on `watcher_occurrences`
- `jarvis:watchers:dispatch` every 5 minutes
- Notification Center / Web Push via `WatcherReactionExecutor`

Missing: NL routing, local-morning schedule, digest aggregation, capability copy.

## Root cause

Prompt policy treated any known clock/recurrence as a Reminder. Watchers were described as “when something happens.” “Каждое утро проверяй почту” therefore became `create_reminder`. `read_storage_*`-style Gmail tools were one-shot chat reads, not a scheduled watcher. Fallback/capability text did not say Jarvis can monitor Gmail.

## Reminder vs Watcher semantics

Reminder = the user must act (“напомни мне проверить почту”).

Watcher = Jarvis performs the read/check (“проверяй почту и сообщай”).

The same split is in `ReminderToolPrompt`, `WatcherToolPrompt`, `CreateWatcherTool` / `CreateReminderTool` descriptions, and `ConversationContextBuilder`. `CreateReminderTool` reroutes a Gmail-monitor inbound to `create_watcher` so a wrong tool call still creates the digest. Calendar/GitHub follow the same prompt rule; only Gmail digest creation is fully filled in.

## Recurring schedule

No new cron subsystem. `source.schedule.kind=daily_local` + `local_time` (HH:MM) on the existing watcher. Default morning time is `08:00` local (`watchers.defaults.morning_local_time`, same as the productivity brief). Owner timezone remains `Europe/Rome`. Explicit “в 7:30” is stored as `07:30`. After baseline/eval, `next_check_at` is the next local slot. Interval cadence (~8 min) remains for non-scheduled Gmail watchers.

## Gmail baseline

Create still dispatches `EvaluateWatcherJob` immediately. First evaluation writes current message ids into `cursor.seen` and does not notify. Historical inbox is not dumped.

## New-mail cursor semantics

Later evaluations skip ids already in `seen` (fingerprint `gmail|{watcherId}|{messageId}`). Digest occurrence fingerprint is the sorted new ids (or `empty|{localDate}`). Duplicate ids are not re-notified. A second empty digest on the same local day is suppressed.

## Digest generation

`WatcherDigestFormatter` builds a bounded human summary: total count, up to six important sender+subject lines, grouped promotional/technical noise. Zero mail: “С утра новых писем нет.” User-facing text has no Gmail/thread ids and no watcher jargon. Occurrence metadata is bounded filters/counts/summary — no email bodies. Read-only: no mark-as-read, archive, label, or reply.

## Notification delivery

Existing `JarvisNotificationService` (`WatcherTriggered`) + Web Push / Telegram adapters. No new send path.

## Capability awareness

If Gmail tools are on the turn: never claim Jarvis cannot check mail; morning monitoring is `create_watcher`. Disconnected: “Могу это делать, но сначала нужно подключить Gmail.” Missing readonly/modify scope: “Нужно разрешить доступ к Gmail.” Human create confirmation: “Готово. Каждое утро около 8:00 буду проверять Gmail и присылать короткую сводку новых писем.”

## Security

`integration_account_id` / `user_id` stripped from tool `source`. Ownership checked on `watchers.integration_account_id` at create. Evaluation pins only that owned id; a foreign id is `not_found`. Live Gmail client will not use another user’s account.

## Files changed

- Watcher schedule/digest: `WatcherSchedule`, `WatcherDigestFormatter`, `WatcherDigestRequest`, `ProactiveCheckIntent`, evaluation/dispatch/service/reaction, Gmail source/client
- Routing: `CreateWatcherTool`, `CreateReminderTool`, reminder/watcher prompts, `ConversationContextBuilder`
- Presentation: `HumanWatcherDescription`
- Config: `config/watchers.php`
- Tests authored (not executed)
- Docs: CURRENT_STATE, WATCHERS_AND_AUTOMATIONS, INTEGRATIONS, CONVERSATION_ENGINE, this report

## Tests authored but NOT executed

- `tests/Unit/Watchers/ProactiveCheckIntentTest.php`
- `tests/Unit/Watchers/WatcherScheduleTest.php`
- `tests/Unit/Watchers/WatcherDigestFormatterTest.php`
- `tests/Feature/RecurringGmailMonitoringTest.php` — reminder vs watcher routing, daily digest create, baseline then new mail, duplicate suppression, zero digest, ownership, disconnected Gmail, missing scope, capability prompt
- Prompt / human-description assertion updates

`php artisan test` / phpunit / Pest were not run.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty`, `composer validate`, `npm run build`, `git diff --check`, `php artisan route:list`, `php artisan schedule:list`.

Scheduler was not run by hand. Watcher evaluation was not executed live. Gmail was not called.

## Owner validation steps

1. “Проверяй каждое утро почту и сообщай мне, что нового пришло.” → automation/watcher, not a reminder.
2. Automations UI shows the human Gmail monitoring sentence.
3. Temporarily move the schedule earlier or wait for the next morning.
4. Watcher reads Gmail itself.
5. Summary contains only new mail after baseline.
6. A repeat run does not duplicate old mail.
7. “Напомни мне завтра проверить почту.” still creates a Reminder.

Until then: **READY FOR OWNER VALIDATION**.

## Production safety

No Owner watcher rows created. No production user data edited. No live Gmail/HTTP. No email sent. No tests executed on this server.
