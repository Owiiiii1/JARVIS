# Cross-mode Confirmation Lifecycle Fix

## Starting HEAD

`40ecc57a6a9d5651e40bb3d934c9d74a07d1362d` (`feat: add admin google oauth configuration`), equal to `origin/main` at the start of this task.

Branch `main`. No dependency changes.

## Owner live bug

Owner created a Gmail send action. Jarvis showed a confirmation card. Owner confirmed in Text chat. The email sent. After switching to Voice, the same card stayed on top: Cancel did nothing useful, Confirm hit a backend error because the action was already resolved.

## Root cause

Workspace Voice overlay selected the latest message with `pending_confirmation.id` and ignored confirmation status. Message metadata keeps the original confirmation snapshot after execute. Serializer emitted that snapshot without live `tool_confirmations.status`. Local `messages` were not patched after Confirm/Cancel, so Text → Voice reused a stale actionable card.

HTTP `resolveConfirmation` aborted 404/409 on a non-pending row, so a second Confirm surfaced as an error instead of a quiet already-resolved response.

## Confirmation lifecycle

`pending` → `confirmed` / `executed` / `cancelled` / `expired`.

Only `pending` (and not past `expires_at`) is actionable in Text cards, Voice overlay, after mode switch, Inertia remount, or conversation refresh.

## Frontend state fix

`applyTurnPayload` / `resolveConfirmation` patch the matching message with the backend confirmation card (`status` included). `already_resolved` updates local state and does not append a turn or show a raw 409/422.

Shared helper: `resources/js/personal-workspace/confirmationState.js`.

## Voice overlay fix

Overlay uses `isActionableConfirmation` (`status === 'pending'` and not expired). Resolved history never hangs at the top of Voice. Text and Voice share the same message list and the same confirmation id.

## Backend idempotency

`PersonalChatSurfaceService::resolveConfirmation`:

- unknown / foreign id → 404
- pending and latest → existing `да` / `отмена` turn (exactly-once execute via `ToolConfirmationService`)
- already executed / cancelled / expired / superseded → HTTP 200 `{ already_resolved: true, confirmation }` with no second sendTurn and no external write

`MessageHistoryService` hydrates live status (batch lookup on history pages).

## Expired state

Serializer and overlay treat past `expires_at` as `expired` without polling. Confirm/cancel after expiry return `already_resolved` and do not execute.

## External-action safety

`executeConfirmed` lock and one-time `Executed` status are unchanged. Duplicate HTTP Confirm cannot reach a second Gmail send. No live Gmail/Google calls were made for this fix. Production confirmation rows were not edited by hand.

## Files changed

- `app/Services/Conversations/MessageHistoryService.php`
- `app/Services/Conversations/PersonalChatSurfaceService.php`
- `app/Services/Conversations/ConversationAiService.php` (snapshot includes `status`)
- `resources/js/personal-workspace/confirmationState.js`
- `resources/js/personal-workspace/PersonalWorkspace.jsx`
- `resources/js/Pages/Cabinet/Chat.jsx`
- tests (authored, not executed)
- docs: CURRENT_STATE, INTEGRATIONS, CONVERSATION_ENGINE, WEB_WORKSPACE, this report

## Tests authored but NOT executed

- `tests/Feature/Http/Controllers/Jarvis/JarvisConfirmationControllerTest.php` — duplicate confirm, duplicate cancel, expired confirm, foreign 404, guest
- `tests/Unit/Conversations/MessageHistoryConfirmationTest.php` — serializer executed vs pending; expired snapshot
- `tests/Unit/WorkspaceConfirmationLifecycleTest.php` — Voice selector requires pending status

`php artisan test` / phpunit / Pest were not run.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty`, `composer validate`, `npm run build`, `git diff --check`, `php artisan route:list` (confirm/cancel routes).

## Owner revalidation steps

1. Create a Gmail send confirmation in Text.
2. Confirm. Card should become non-actionable (“Письмо отправлено”).
3. Switch to Voice: no overlay card.
4. Switch back to Text: still no Confirm/Cancel.
5. Repeat with Cancel and with waiting past Expires.
6. A second Confirm on a resolved card must not send another email and must not show a raw error.

## Production safety

No phpunit. No live Google HTTP from Cursor. No manual updates to production `tool_confirmations`. Gmail confirmation is **READY FOR OWNER REVALIDATION**, not MANUAL PASS.
