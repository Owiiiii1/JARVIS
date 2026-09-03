# Cursor work report — Reminder Engine + first Conversation Tool

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `c7bbd0f8dc417d99a28d8df07b5ee9663d854149` (`feat: add web cabinet conversations`) |
| Commit message | `feat: add Jarvis reminder engine` |
| Working tree before start | clean |

## Backup

Before migration: `storage/backups/pre_reminders_20260903_184838.sql` (gitignored). Tables: `users`, `channel_identities`, `conversations`, `messages`, `ai_role_settings`. Production chats retained.

## Migration

`2026_09_03_210000_create_reminders_table`

Also updates conversation system prompts so they no longer say tools are unavailable. Owner/user Gemini configs remain enabled.

## Reminder schema

Table `reminders`: `user_id`, nullable `source_conversation_id` / `source_message_id`, `text`, `run_at` UTC, `timezone`, `original_local_time`, `status`, `delivered_at`, `cancelled_at`, `recurrence_rule` (unused), `last_error`, `metadata` json, timestamps.

Status enum: `scheduled`, `processing`, `delivered`, `cancelled`, `failed`.

Indexes: `user_id`, `status`, `run_at`, `(status, run_at)`.

Model `Reminder` has relations only — no scheduling logic.

## Tool architecture

Provider-neutral Tool Layer:

- `JarvisTool`, `ToolRegistry`, `ToolDefinition`, `ToolCall`, `ToolResult`, `ToolExecutionContext`
- First tool: `create_reminder`
- Arguments from the model: `text`, `run_at_local`, optional `timezone` / `original_time_expression`
- `user_id` / `conversation_id` never taken from LLM arguments
- Capability `reminders` checked in Core
- No regex NL parser (`напомни`, `завтра`, …)

## Gemini tool calling

Production conversation provider is Gemini. `GeminiClient` sends `functionDeclarations`, parses `functionCall` into `ToolCall`, and sends `functionResponse` back. Empty assistant text is allowed when tool calls are present.

OpenAI / Anthropic: `supportsTools=false`. A tool-enabled request is refused, not sent silently.

## Multi-tool loop

Inside `ConversationAiService` (Core), not Telegram/Cabinet:

AI → tool call(s) → execute → tool result(s) → AI → possibly more tools → final answer.

Safety limit: **max 5 tool rounds**. Telegram and Web Cabinet share `ConversationTurnService`.

## Timezone handling

Each turn injects current user local datetime and IANA timezone from `users.timezone` (not hardcoded).

Core takes wall-clock from `run_at_local` and applies the user IANA timezone. Conflicting offset is ignored. DST via DateTimeZone. Past instants rejected. Ambiguous day-without-clock: model must ask, tool is not called.

## Scheduler / production cron

Command: `jarvis:reminders:dispatch`  
Schedule: `everyMinute()` + `withoutOverlapping(10)`

Cron (deploy user, idempotent; one line, not duplicated):

```text
* * * * * cd /var/www/jarvis && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows the command.

Reminder dispatch is synchronous inside the scheduled command.

Telegram inbound processing now uses the database queue. The webhook validates the secret, enqueues `ProcessTelegramUpdate`, and returns immediately. A dedicated `telegram` queue worker is guarded by `flock` and restarted from deploy-user crontab.

## Delivery

`ReminderDeliveryService` uses existing `TelegramBotManager::sendTextMessage` (same bot token / Bot API). No second Telegram client. No Gemini call.

Text: `⏰ Напоминание: {text}`

Current linked Telegram identity of that User (re-pair is OK). Delivery is not a semantic assistant message.

## Race / idempotency

Due rows claimed in a transaction: `scheduled` → `processing` with `lockForUpdate` + `skipLocked`. A delivered/processing row is not claimed again. Duplicate Telegram inbound (`channel_message_id`) does not create a second reminder.

## Retry policy

Telegram API failure: return to `scheduled` with `metadata.attempts` and `next_retry_at`. Max **3** attempts, then `failed`. Disabled user: `cancelled`, `reason=user_disabled`. Missing identity at delivery: retry then fail (`telegram_not_connected`).

## Product decision

No linked Telegram identity → **reminder is not created**. Message: «Для получения напоминаний сначала подключите Telegram.» ADR-046.

Recurrence is not implemented. Do not create a one-shot as a fake recurring reminder. ADR-048.

## Tests

PHPUnit: **89 passed**, **1 skipped**. Production DB. Temporary `jarvis-test-*@invalid.local` users and their reminders cleaned by id. `FakeAiChatGateway` / `Http::fake` — no billable Gemini, no real Telegram delivery.

Covered: schema; owner/user create; no Telegram → no row; linked Telegram → create; local→UTC; DST / conflicting offset; past rejected; provenance; Gemini functionCall → ToolCall; functionResponse back; final text after tool; max tool rounds; Telegram and Web same loop; duplicate inbound; scheduler due; delivered once; claim twice; Telegram failure retry; disabled user cancelled.

## Build

`npm run build` after Settings → Users `reminders_count` column.

## DB counts (after tests)

| Table | Count |
| --- | --- |
| users | 1 |
| channel_identities | 1 |
| conversations | 2 |
| messages | 8 |
| reminders | 0 |
| ai_role_settings | 3 |

Owner production chats retained. No owner reminders created by this work.

## Manual smoke status

**awaiting manual owner smoke**

Not executed by Cursor. Owner should write in Telegram: `Напомни мне через 2 минуты проверить Jarvis`. Expect Gemini confirm, `scheduled` row, then `⏰ Напоминание: проверить Jarvis`, status `delivered`, no duplicate.

Web Cabinet create (with Telegram pairing) is architecturally the same tool loop; delivery remains Telegram.

## Known issues

- Switching conversation AI away from Gemini disables reminder tools until that provider supports tools.
- Recurrence / cancel / list tools not implemented.
- A crash after Telegram send and before `delivered` could leave `processing` (at-most-once; no automatic reclaim).
- Manual owner smoke not yet run.

## Production incident and hotfix

The first owner smoke exposed two coupled defects:

- Gemini 3 function-call responses require the original `thoughtSignature`; omitting it caused the post-tool model call to fail or run until PHP terminated the webhook.
- Unfinished historical user messages were included and merged into later turns. Consequently, follow-up messages such as «Ты тут?» inherited the earlier reminder request and created additional reminders.

Fixes:

- preserve native Gemini response parts and `thoughtSignature` through `functionResponse`;
- process Telegram updates on a dedicated database queue, outside the webhook request;
- exclude previous `pending` / `failed` inbound turns from a new model context;
- make `create_reminder` idempotent for `source_message_id`;
- retry stale pending turns safely and use a confirmation fallback if the post-tool model call fails.

Production recovery marked stale owner turns as failed without deleting messages. No scheduled owner reminders remained. The latest «Ты тут?» was retried successfully and Jarvis sent a normal assistant response.

## Next milestone

Milestone 11 — Telegram Groups (see `Docs/IMPLEMENTATION_PLAN.md`).
