# Workspace UX Cleanup

## Starting HEAD

- Baseline: `75ac18d74755a8d154dab19dd6f3469ea64fcc9e` `feat: add Tasks and proactive productivity`
- Working tree was clean; `HEAD == origin/main` before work.
- No migrations. Production DB `jarvis` was not migrated, refreshed, or mass-updated.

## Previous Workspace structure

Main Workspace mixed work surfaces with configuration:

- Header already had Reminders / Tasks / Notifications / a gear Settings control / Voice mode toggle.
- Settings was one Modal stuffing profile, timezone, voice, productivity, password, onboarding, General Prompt, Admin Integrations link, logout.
- Owner right context panel duplicated Reminders and also hosted Integrations status + Memory counts + General Prompt.
- Ordinary `/chat` users never saw that context panel, but they still had the unstructured Settings modal.

Chat turns returned `active_reminder_count` only. Task badges, notification badges, and open panels stayed stale until F5.

## Settings information architecture

Settings is now a Workspace panel (`WorkspaceSettings`), not a standalone admin page.

Sections:

- Profile
- Assistant
- Memory (if `memory` capability)
- Productivity (if tasks / reminders / notifications)
- Voice (if `voice` capability)
- Integrations (Owner admin integrations and/or Telegram DM)

No empty Advanced section.

Desktop: left navigation, right current section. Mobile: section list → detail (not two columns at once). Direct section via allowlisted `?settings=memory` / `?settings=integrations` on the initial page load. Opening Settings from the header does not call `history.replaceState` (that wiped Inertia page props and showed «No chats.»). Arbitrary URLs are ignored.

## Main Workspace cleanup

Removed from main chrome:

- Owner context Integrations block
- Owner context Memory block / General Prompt button
- Duplicate Reminders mini-list in context
- Monolithic Settings modal / separate General Prompt modal

Main screen is conversations + current chat/voice + Task / Reminder / Notification centers. Header actions: Tasks, Reminders, Notifications, Text/Voice, **Настройки** (icon + label on desktop; icon + `aria-label` on mobile). Owner Admin and Projects context toggle remain compact.

## Memory relocation

Memory management lives in Settings → Memory.

It reuses the existing Memory Engine summary only: active facts count, active topics count, last completed analysis. No new Memory product. Regular users do not see raw internal tables. Owner/admin gets a diagnostics pointer to Admin Users (existing `UserMemoryController` path), not a second engine.

## Integrations relocation

Integrations live in Settings → Integrations.

Owner: compact cards from the current Integration Registry + Web Research workspace summary, Connect/Manage → Admin Integrations. Personal Telegram pairing is a separate card.

Regular user: Telegram pairing / connected-as / response-mode hint only. Google, GitHub, and Web Research admin cards are not rendered without `integrations` capability. Backend authorization is unchanged.

## Profile / Assistant settings

Profile: name, email display, timezone, onboarding / Знакомство, password, logout. Existing `settings.profile.update` / `settings.password.update` / `onboarding.start`.

Assistant: current identity (name, personality, interaction style, about user) as already stored on `user_assistant_profiles` (still edited via chat tools) plus User General Prompt on the existing `settings.prompt.update` endpoint. Visually separated from Memory.

## Productivity settings

Daily Brief, Evening Review, Weekly Review, proactive suggestions, Web Push enablement. Task Center and Reminder Center stay on the main screen; their preference forms moved here. Same `settings.productivity.update` and reminder push endpoints.

## Voice settings

Curated six-voice catalog and `users.voice_id` via the existing profile update endpoint. Admin provider keys stay in Admin Integrations.

## Owner vs User visibility

Regular user sees Profile, Assistant, Memory, Productivity, Voice, Telegram pairing. They do not see Admin integration configuration, Owner Projects configuration, Gmail/GitHub cards, or provider secrets.

Owner sees the full allowed set, including Integrations status cards and the Projects context panel. UI gating does not replace backend authorization.

## Live refresh root cause

`PersonalChatSurfaceService::turnPayload()` only returned `active_reminder_count`. Frontend `applyTurnPayload()` updated messages and that reminder count. It never refreshed `activeTaskCount`, `unreadNotificationCount`, or open panels after a successful foreground turn (chat send, confirmation resolve, Voice `onTurn`).

The assistant reply text was never used as a mutation detector.

## Live refresh architecture

After every successful completed foreground turn:

1. Turn JSON now includes `active_task_count` and `unread_notification_count` as well as reminder count and `assistant_profile`.
2. `refreshProductivity()` applies those counts, bumps `productivityRefreshToken`, and fetches `GET …/workspace/status`.
3. Open panels reload their payload; closed panels only get counts.

No `window.location.reload()`, no Inertia page reload, no `setInterval` polling, no WebSocket/SSE.

Polling is unnecessary: the user is already waiting on the turn response, which is the exact moment a chat-driven mutation is known.

Scheduler-side due tasks / briefs / reminders still appear via Web Push, next panel open, navigation, or reload.

## Badge refresh

Header badges bind to React state updated from the turn payload and then confirmed by `workspace.status`.

## Panel refresh

`TasksPanel`, `RemindersPanel`, and `NotificationsPanel` accept `refreshToken`. Their load effects depend on `open` + `refreshToken`, so a closed panel does not fetch full lists.

## Routes/endpoints

Added (both surfaces):

- `GET /jarvis/workspace/status` → `jarvis.workspace.status`
- `GET /chat/workspace/status` → `chat.workspace.status`

Payload: `{ tasks.active_count, reminders.active_count, notifications.unread_count, assistant_profile, general_prompt, telegram (no access_code), memory }`. No secrets.

Existing settings / task / reminder / notification / voice / prompt endpoints reused.

## Tests

- `tests/Unit/WorkspaceUxCleanupTest.php` — static UI contracts: refresh hook, no reload/polling, Memory/Integrations not on main, Settings sections, allowlisted `?settings=`.
- `tests/Unit/WorkspaceStatusRoutesTest.php` — dual-surface status routes + turn counts.
- `tests/Feature/Http/Controllers/Jarvis/WorkspaceStatusControllerTest.php` — guest redirect; temporary user GET status (no RefreshDatabase).
- Existing `TaskWorkspaceRoutesTest` updated for Productivity living in Settings.

`php artisan test --compact` on those files: passed.

## Build

`npm run build` succeeded. `vendor/bin/pint --dirty --format agent` passed. `composer validate` passed. `php -l` on touched PHP passed. `git diff --check` run at commit time.

## Production safety

No migrations. No `migrate:fresh` / `RefreshDatabase`. No mass deletes. No live Telegram / Web Push / Gmail / Calendar / AI calls. Status GET is read-only counts/summaries. Temporary-user feature test deletes only `@invalid.local` rows created by the test helper.

## Manual checklist

A. `/jarvis` main screen: chat + centers, one **Настройки**, no large Memory/Integrations blocks.
B. Settings: Profile / Assistant / Memory / Productivity / Voice / Integrations as nav + detail, not one form wall.
C. Memory works from Settings.
D. Integrations work from Settings with current permissions (Owner Admin manage; user Telegram pairing).
E–G. Chat-created task / complete task / reminder update badges and open panels without F5.
H. Ordinary user Settings structured; Owner-only integration cards absent.
I. Narrow viewport: Settings list → detail, no horizontal mash.

Owner live confirmation of E–I is still the product MANUAL PASS; this milestone is the code/UX refactor.
