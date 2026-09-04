# Cursor Work Report — Milestone 19 Gmail

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`  
**Branch:** `main`

Live Google / combined Calendar+Gmail smoke deferred by Owner. Cursor did not connect a real Google account, read a production inbox, send mail, create a real draft, or change labels.

---

## Before HEAD

`520b48c` — `feat: add Google Calendar tools` (M18)

---

## Migrations

None. Reused `integration_accounts`, `tool_execution_logs`, `tool_confirmations`. No local Gmail mailbox tables. No idempotency table: send is guarded by confirmation one-time semantics.

---

## Gmail scopes

Least privilege (not `mail.google.com`):

- `https://www.googleapis.com/auth/gmail.readonly` — search / read / list / labels
- `https://www.googleapis.com/auth/gmail.compose` — drafts and send
- `https://www.googleapis.com/auth/gmail.modify` — labels, read/unread, archive

Card “Gmail enabled” requires all three. Runtime: read = readonly or modify; draft = compose; send = compose or send; labels = modify.

Identity (`openid email profile`) and Calendar (`https://www.googleapis.com/auth/calendar`) stay as in M17/M18.

---

## Incremental OAuth

`GET /settings/integrations/google/connect?intent=gmail` adds Gmail scopes to the identity set. `include_granted_scopes=true`. Stored scopes = union of existing granted (identity + Calendar if present) + newly granted Gmail. Missing refresh token in the incremental response does not overwrite the existing refresh token.

Google card shows Identity / Calendar / Gmail separately. Connected Google is not automatically Gmail-enabled. Enable Gmail appears until all Gmail scopes are present. No live API call on page load.

---

## Adapter / service

`GoogleGmailService` is the only Gmail HTTP client.

- `searchMessages`, `listMessages`, `getMessage`, `getThread`, `listLabels`, `createDraft`, `sendMessage`, `modifyLabels`
- Access token only via `GoogleCredentialService::getValidAccessToken()`
- Laravel HTTP client, JSON, timeouts from `config/google_gmail.php`
- GET/search/read/list may retry once; draft/send/modify do not retry
- Errors normalized: `google_not_connected`, `gmail_scope_required`, `gmail_message_not_found`, `gmail_thread_not_found`, `gmail_forbidden`, `gmail_rate_limited`, `gmail_send_failed`, `gmail_invalid_recipient`, `gmail_unavailable`, `gmail_conflict`

Tools contain no Gmail HTTP.

---

## Tool list

Owner (`gmail` capability):

| Tool | Operation |
| --- | --- |
| `search_gmail` | read |
| `list_gmail_messages` | read (default INBOX; `unread=true` → `is:unread`) |
| `get_gmail_message` | read |
| `get_gmail_thread` | read |
| `list_gmail_labels` | read |
| `create_gmail_draft` | write (explicit command allowed; model-proposed → confirm) |
| `send_gmail_message` | write, **always** confirmation_required |
| `modify_gmail_labels` | write (read/unread/archive/custom labels) |

No separate reply tool. Reply uses draft/send + `reply_to_message_id` / `thread_id`.

Calendar tools remain. Normal user unchanged (reminder + history search). Forged Gmail execute is denied.

---

## MIME parsing

`GmailMimeParser`: text/plain first; text/html → sanitized plain text; nested multipart recursion; base64url decode; attachment metadata only (filename, mime, size, attachment id). No raw base64 MIME in tool results. No attachment download.

`GmailMimeBuilder`: To/Cc/Bcc, RFC 2047 subject, text/plain UTF-8, In-Reply-To / References, base64url raw. Header CRLF rejected. Outbound HTML composer out of scope.

---

## Body limits

`config/google_gmail.php`: max search/list/thread messages, body chars, total thread chars, snippet, recipients, subject, outbound body, labels, HTTP timeouts, GET retries.

Message body and thread totals set `truncated=true` when capped. No mailbox dump.

---

## Search / list / thread

Search uses Gmail query syntax. List defaults to INBOX. Unread filter supported. Results are compact (ids, subject, sender, recipients, date, snippet, labels, unread, thread id) — no full body on search/list.

Thread: chronological, bounded messages; service does not AI-summarize.

---

## Drafts

`create_gmail_draft` creates a draft only. Validation: emails, max recipients, subject/body length, empty recipients, header injection. Explicit user command may run; model-proposed requires confirmation. No send. Write HTTP is not retried; a repeated user request may create another draft.

---

## Send confirmation

`send_gmail_message` always requires a persisted `tool_confirmations` row, including explicit «отправь». Initial execute: no Gmail send HTTP. Confirm: exactly one `messages.send`. Repeat confirm / cancel / expire: no send. Other user cannot confirm.

Pending summary/preview: recipients, subject, bounded body. No tokens or internal account ids. Web Confirm/Cancel plus existing conservative `да`/`yes` parser.

---

## Reply threading

Service fetches original headers and sets `threadId`, `In-Reply-To`, `References`, To, and `Re:` subject. Not a detached new thread.

---

## Labels / read / archive

`modify_gmail_labels` add/remove label ids. Mark read = remove `UNREAD`. Mark unread = add `UNREAD`. Archive = remove `INBOX`. No trash / permanent delete. Label ids validated for format/bounds.

---

## Logging privacy

`tool_execution_logs` metadata: `result_count`, `operation`, `truncated`, `confirmation_id`, safe error. Not stored: email body, subject, addresses, OAuth tokens, Gmail raw response.

Gmail calls update integration account `last_used_at` / `last_success_at` / `last_error_*` through `ToolExecutionService`.

---

## Tests

Automated only. `Http::fake`. No live Google.

Covered: owner vs user definitions; disconnected; missing scope; incremental Gmail OAuth (Calendar + refresh preserved); search bounds; MIME variants + truncation; thread bounds; unread/inbox query; draft explicit vs proposed; send confirm/repeat/cancel/expire/cross-user; reply headers; label read/archive; validation without HTTP; no write retry; log privacy; token refresh via `GoogleCredentialService`; Gmail→Calendar multi-tool loop. M18 Calendar tests stay green.

---

## Build

Integrations card + Cabinet confirmation preview: `npm run build`.

---

## Production counts

After verify (temporary test users cleaned):

- users 1
- conversations 7
- integration_accounts 0
- tool_execution_logs 0
- tool_confirmations 0
- reminders 11
- no `emails` / `gmail_messages` / `gmail_threads` tables

Combined live smoke not run. Production inbox untouched.

---

## Worker status

Unchanged. No Gmail schedule/cron. `queue:failed` / `schedule:list` verified.

---

## Live smoke deferred

Cursor did not:

- set Google Cloud client credentials
- enable Gmail API in a live project
- connect a real account
- read/send/draft/label real mail

---

## Combined Google smoke plan (later, Owner)

1. Configure Google Cloud OAuth.
2. Enable Calendar API.
3. Enable Gmail API.
4. Add env Client ID/Secret.
5. Connect Google.
6. Enable Calendar.
7. Enable Gmail.
8. Test Calendar read/freebusy/create/update/delete.
9. Test Gmail search/read/thread.
10. Test draft.
11. Test confirmed send.
12. Test mark read/archive.
13. Test Gmail→Calendar multi-tool.
14. Test token refresh.
15. Disconnect/reconnect.

Do not execute now.

---

## Known issues

- Draft create is not idempotent across a repeated user request (HTTP write is not retried).
- Outbound attachments and trash/delete are out of scope.
- Proactive inbox monitoring / watch is later.

---

## Next step

Owner combined Google integration setup and live smoke (Calendar + Gmail) when ready. Milestone 20 — Mobile/Desktop API — is the next planned product milestone.
