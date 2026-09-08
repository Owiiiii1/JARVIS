# Gmail Event Monitoring

## Starting HEAD

`45bd7dabd4c359b7d900c7d56c26bd0397903979` (`fix: harden agent runtime recovery`).

Branch `main`. No dependency changes.

## Live production failure

Owner asked Jarvis to wait for school/academy mail (`@marcellinequadronno.it`, `@accademiaucraina.it`) and notify when messages arrive. Jarvis claimed 5–15 minute Gmail polling. Production had no Gmail event watcher: `create_watcher` for Gmail failed `invalid_config` twice, then a Knowledge watcher (#190) was created (`entity_event_type` / fictional `new_email`). Later domain clarification only called `search_gmail`. The morning digest watcher is a separate daily product.

## Root cause

1. Generic “monitor mail” intent was steered into a morning digest (or, before that, into Knowledge).
2. Gmail create required a sender/query the model often omitted, then silently accepted a Knowledge fallback.
3. `new_email` is not a Knowledge event type (`email_received` is). Knowledge is not a Gmail poller.
4. Success text was inferred from user intent, not from a successful Gmail `create_watcher`.
5. Gmail watchers defaulted to `one_shot` and a 1h cooldown, which is wrong for continuous mail alerts.

## Reminder vs Digest vs Event semantics

- Reminder: the user acts at a known time (“напомни проверить почту”).
- Digest: Jarvis summarizes new mail on a local daily schedule (“каждое утро дай сводку”).
- Event: Jarvis polls Gmail and notifies on matching new messages (“жди письмо от школы”). Not a digest.

## Event watcher canonical config

`trigger_type=gmail_message`, `condition_type=new_item`, `mode=recurring`, structured `senders` / `sender_domains` / `subject` / `thread_id`. Runtime compiles a Gmail `OR` query. No `daily_local`. Cooldown 0.

## Sender/domain normalization

`GmailWatcherQuery` accepts sender email, domain (`example.com` / `@example.com`), lists of either, subject, or an existing safe query. The model does not need Gmail query grammar.

## Multiple domains

One watcher. `sender_domains: [marcellinequadronno.it, accademiaucraina.it]` → `(from:a OR from:b)`. Human copy names the domains.

## Recurring / one-shot semantics

Continuous wording defaults to recurring. One-shot only for explicit first-letter / one-reply phrasing.

## Poll cadence

Existing `jarvis:watchers:dispatch` (every 5 min) + Gmail cadence (~8 min). Chat does not promise an exact interval.

## Baseline semantics

First evaluation stores current matching ids. Later evals notify only new ids. “Already in inbox?” is a `search_gmail` then watcher, not a silent later alert of old mail.

## No wrong-type fallback

Gmail event/digest inbound cannot create Knowledge/Reminder/project watchers. Missing sender/domain returns `gmail_filter_required`. Disconnected Gmail still asks to connect.

## Grounded success responses

`create_watcher` payload includes `kind` (`gmail_event` / `gmail_digest` / `failed`) and `confirm_as`. Synthesis and fallback may claim Gmail monitoring only after success + Gmail kind.

## Conversational watcher refinement

“И ещё следи за письмами от example.com” merges into the unique trusted (or unique active) Gmail event watcher. Ambiguous sets ask. Ids are not guessed among several.

## Security

`user_id` / `integration_account_id` stripped. Ownership checks unchanged. Event watcher is read-only. Metadata bounded to sender/subject/ids — no bodies.

## Notifications

Existing occurrence → Notification Center / Telegram. No separate Telegram path.

## Existing broken watcher remediation

Cursor did **not** modify Owner watcher #190. After deploy, Owner cancels it and creates a Gmail event watcher in chat (see `Docs/WATCHERS_AND_AUTOMATIONS.md`).

## Files changed

New: `GmailWatcherQuery`, `GmailEventRequest`, `GmailEventMonitoringTest`, `GmailWatcherQueryTest`.

Updated: create/update watcher tools, reminder reroute, intent split, WatcherService, LiveGmail client, evaluation multi-match, prompts, grounding, docs.

Unrelated dirty workspace files were left unstaged.

## Tests authored but NOT executed

Feature `GmailEventMonitoringTest` (intents 1–14). Unit query/intent/presentation/fallback. Existing digest/reminder tests updated. PHPUnit was not run.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty --format agent`, `composer validate`, `git diff --check`. No live Gmail, no watcher dispatch, no phpunit.

## Owner validation

Cancel #190. Ask Jarvis to watch the two school/academy domains. Confirm Автоматизации shows a recurring Gmail event watcher. Send two matching test emails; both should notify. Add `example.com` as a follow-up. Unrelated mail silent. Digest and reminder phrases still split.

## Production safety

No Owner watcher/mail/account writes from Cursor. No live Gmail API. No phpunit on this server.
