# Current JARVIS State Audit

**Audit date:** 2026-09-05
**Project:** `/var/www/jarvis`
**Repository:** `https://github.com/Owiiiii1/JARVIS.git`
**Audit baseline:** `origin/main` at `b099107ebf2f6c1f8ed37ffb989a4164a57cb52a` (`docs: record current voice behavior`)

Status terms in this report:

- **IMPLEMENTED** — present in committed `origin/main` code.
- **MANUAL PASS** — explicitly recorded as Owner-confirmed in project documentation.
- **MANUAL PARTIAL** — only a documented subset was Owner-confirmed.
- **IMPLEMENTED / NOT VALIDATED** — code exists, but no Owner confirmation is recorded.
- **KNOWN BUG** — current docs record a production-visible problem, or the audit found a concrete repository/runtime mismatch.
- **DEFERRED / CANCELLED** — explicitly outside current execution.

The audit priority was actual `origin/main`, then committed code/routes/migrations/services, read-only production schema/status, current docs, and already-recorded Owner validation. Pre-existing uncommitted files were inspected only to identify drift; they were not treated as committed implementation.

## Repository

- `git fetch origin` completed successfully.
- Current branch at audit start: `main`.
- Local `HEAD`: `b099107ebf2f6c1f8ed37ffb989a4164a57cb52a`.
- Fetched `origin/main`: `b099107ebf2f6c1f8ed37ffb989a4164a57cb52a`.
- Ahead/behind at audit start: `0/0`.
- Remote fetch/push URL: `https://github.com/Owiiiii1/JARVIS.git`.
- Runtime stack observed: Ubuntu 24.04, PHP 8.5.8, Laravel 13.30.1, Composer 2.7.1, MySQL 8.0.46.
- Queue connection: `database`.
- Production database: `jarvis`, 48 tables, approximately 2.80 MB.
- `php artisan migrate:status`: all 35 listed migrations are `Ran`, including `user_channel_preferences` and `2026_09_05_202207_add_voice_id_to_users_table`.
- `php artisan route:list --except-vendor`: 136 application routes.

## Recent commits

Last 20 commits on fetched `origin/main`, newest first:

1. `b099107` — docs: record current voice behavior
2. `ee7d4f0` — fix: make assistant voice a user preference
3. `e25bf3a` — feat: add curated assistant voice selection
4. `79ae9af` — fix: brighten voice orb on mobile
5. `2b3a972` — fix: keep web voice in push-to-talk mode
6. `2e62bcb` — fix: distinguish technical AI failures
7. `9fdf69a` — fix: guarantee safe AI fallback responses
8. `07522cb` — fix: handle blocked and empty AI responses
9. `e7a74c8` — feat: add Telegram voice input
10. `a8e05d5` — feat: add Telegram voice replies
11. `e4f3ea1` — docs: add Telegram voice reply roadmap
12. `6e18325` — docs: realign Jarvis roadmap and current architecture
13. `ef6ed9c` — fix: improve voice activity silence detection
14. `de7d579` — feat: add assistant onboarding and reminders panel
15. `7d9c83f` — feat: add hands free voice turn detection
16. `72b4e3c` — feat: complete user administration and isolation controls
17. `00b54e0` — feat: add Gemini speech to text provider
18. `291e248` — feat: add shared personal workspace for users
19. `bdb4283` — feat: add audio reactive Jarvis voice orb
20. `54cd569` — feat: add voice runtime foundation

The recent sequence is coherent: Voice Runtime/STT/Orb, shared user workspace and isolation, hands-free/VAD, onboarding/reminders UI, M26D docs, Telegram voice both directions, AI fallbacks, then the current push-to-talk and per-user voice changes.

## Working tree

The working tree was **not clean before this audit**. These pre-existing changes were present after `git fetch`:

- Modified: `CLAUDE.md`.
- Modified: `app/Services/Voice/Exceptions/VoiceException.php`.
- Modified: `app/Services/Voice/Providers/ElevenLabsTextToSpeechProvider.php`.
- Modified: `app/Services/Voice/Providers/GeminiSpeechToTextProvider.php`.
- Modified: `composer.json`.
- Modified: `composer.lock`.
- Untracked: `.claude/` skill files.
- Untracked: `.mcp.json`.
- Untracked: `boost.json`.

Tracked pre-existing diff size: 645 insertions and 50 deletions across six files.

The dependency/rules changes install Laravel Boost but are absent from `origin/main`. More importantly, the local voice provider edits are also absent from `origin/main`:

- Local Gemini STT changes encode an empty `audioTranscriptionConfig` as `{}` and improve provider error classification/logging. Committed `origin/main` still calls `transcriptionConfig()` directly, so an empty PHP array serializes as `[]`.
- Local ElevenLabs changes add response diagnostics and retry a configured fallback voice for selected voice failures. Committed `origin/main` has no such retry.
- Local `VoiceException` accepts TTS failure context; committed `origin/main` does not.

These files may affect the running checkout even though GitHub does not contain them. A clean deployment from `origin/main` would not include those fixes. The audit did not modify, stage, discard, or commit them.

`Docs/Development/Cursor_Work_Report.md` was clean at audit start. It is the only file intentionally changed by this audit.

## Core architecture

- JARVIS has one Core for users, conversations, messages, context, tools, memory, and AI orchestration.
- Web, Telegram DM, Web Voice, and Telegram voice are surfaces/adapters over `ConversationTurnService`; voice is a modality, not a second assistant.
- `conversations.user_id`, `messages.user_id`, and ownership-aware services form the primary personal-space boundary.
- `ConversationContextBuilder` assembles platform/role configuration, structured assistant identity, User General Prompt, summaries, memory, current messages, attachments/files, and tools through `ContextBudgetManager`.
- Owner Conversation AI, Owner Analysis AI, and Default User Conversation AI remain separate role configurations.
- Recent AI failure handling distinguishes safety blocks, empty provider responses, and technical provider failures. It can retry safety-blocked content with safe-answer instructions and produces explicit fallback text instead of silently losing the turn.
- Database-backed queues are used. At audit time there were 0 pending jobs and 34 retained failed jobs, all on queue `memory`, dated 2026-09-05 12:02–13:21.
- Scheduler entries:
  - `jarvis:reminders:dispatch` every minute.
  - `jarvis:attachments:purge-ephemeral` hourly.
  - `jarvis:voice:cleanup-temp` every five minutes.
  - bounded `queue:work database --queue=memory,default` every minute.
- Telegram processing also uses its dedicated host worker arrangement documented by the project; it is not listed in Laravel's scheduler output.

## Users / isolation

Production aggregate at audit time: four active users — one owner and three regular users; no disabled user was present.

Regular-user capabilities in committed code:

- chat
- memory
- Telegram DM
- reminders
- cabinet compatibility
- Personal Workspace
- profile
- Web Research
- Voice
- Storage tools/chat files

Regular users do not receive Admin, user administration, integration administration, projects, Telegram groups/group analysis, Gmail, Google Calendar, GitHub, impersonation, or system AI settings.

Implemented controls:

- Owner workspace: `/jarvis`; regular-user workspace: `/chat`; `/cabinet` is compatibility routing.
- Both workspace route groups require `auth` and `user.active`; owner/personal workspace middleware performs role routing.
- Admin routes require Admin middleware plus `user.active` and `owner`.
- Disabled login is rejected; active middleware blocks later requests; conversation turns, tools, Voice Runtime, Telegram voice input, reminder create/delivery, projects, groups, and integrations also check active status in their service layer.
- `ConversationService::ensureOwned` and `ConversationTurnService` enforce conversation ownership.
- `StoredFileService` and storage tools scope by `user_id`; the owner-only Storage page is separate from regular-user storage tools.
- `VoiceRuntimeService` checks both session `user_id` and conversation ownership.
- Assistant profile operations derive the user from tool context and query the user's single profile.
- Reminder list/cancel queries include `user_id`.
- Impersonation is owner-only, session-scoped, only targets active `role=user`, and removes Admin authority while active.
- Projects and Telegram groups have policies/capability checks; projects additionally enforce owner IDs.

Validation status:

- User creation, login, `/chat`, and normal requests: **MANUAL PASS**.
- M25U.2 administration/isolation controls: **IMPLEMENTED**, with the core flow **MANUAL PASS**.
- Prepared cross-user/IDOR and hostile authorization campaign: **NOT EXECUTED**. Existing tests were not rerun against production during this audit.
- `tests/Unit/UserCapabilitiesTest.php` still lists `storage`, `web_research`, and `voice` as denied regular-user capabilities, but `UserCapability::forRegularUser()` includes them. This is committed test/product drift, not a live capability denial.

Potential gaps, not proven vulnerabilities:

- Several legacy `/cabinet` JSON/controller routes remain even though `/chat` is canonical; they rely on the same auth/ownership services and should remain in future isolation review scope.
- No hostile production test was performed, so static ownership checks do not upgrade the A/B isolation campaign to MANUAL PASS.

## Web Workspace

- M25U.1 is **IMPLEMENTED** through the shared `resources/js/personal-workspace/PersonalWorkspace.jsx`.
- `/jarvis` and `/chat` expose the same conversation UI with role-dependent capabilities and chrome.
- Implemented features include conversation list/create/rename/history, composer, image/file upload, confirmations, Voice mode, profile/timezone, General Prompt, personal TTS voice, onboarding entry, and reminders panel.
- `JarvisWorkspaceController` supplies ownership-checked conversation data, UI capabilities, settings, assistant profile, Voice client limits, and active reminder count.
- Current production bundle timestamp was 2026-09-05 22:26+02:00. Static bundle inspection found the current Personal Workspace, reminders labels, Assistant voice UI, and VoiceSession radio flow.
- Owner/regular-user core workspace workflow: **MANUAL PASS** as recorded.
- Individual newer UI changes — push-to-talk-only Voice and per-user voice selection — are **IMPLEMENTED / NOT VALIDATED** unless separately confirmed after their commits.

## Voice Web

Committed implementation:

- M23 `VoiceRuntimeService`, `voice_sessions`, authenticated Voice routes, state machine, ephemeral upload storage, STT/Core/TTS pipeline: **IMPLEMENTED**.
- M23.2 Gemini STT provider and DB-configured model: **IMPLEMENTED**.
- M24 Three.js/WebGL Orb plus CSS fallback: **IMPLEMENTED**.
- M24.1 hands-free local VAD and M24.1.1 silence hotfix were implemented and Owner-confirmed, but are now **historical/superseded**, not the current capture UX.
- Current `VoiceSession` uses only push-to-talk («Рация»): hold to capture, release/cancel/lost pointer to submit; pressing while speaking/thinking interrupts first. The hands-free mode selector was removed.
- Historical VAD helpers (`VoiceTurnDetector`, `voiceTurnDetection.js`) remain in the tree, but the current `VoiceSession` loop does not use silence `end_of_turn` as the product boundary.
- Orb intensity is raised on desktop and further on narrow mobile Web; fallback CSS has matching brightness/saturation.
- Web TTS now resolves `users.voice_id` through `VoiceSettingsService`; six curated ElevenLabs voices are offered in Workspace settings.
- Voice session/controller ownership checks user, conversation, session UUID, capability, and active state.
- Audio is temporary; messages/transcripts remain canonical. `jarvis:voice:cleanup-temp` expires sessions and removes stale files.

Production observations:

- `voice_settings`: STT `gemini`, TTS `elevenlabs`, model `gemini-3.5-transcribe`, spoken-style enabled.
- `users.voice_id` migration is ran.
- 24 production `voice_sessions`, all currently `ended`.
- Configured hard bounds: 2,000,000 bytes and 30 seconds.

Status by requested milestone:

- M23 Voice Runtime: **IMPLEMENTED; MANUAL PASS** as part of the documented end-to-end Voice pipeline.
- M23.2 Gemini STT: **IMPLEMENTED; MANUAL PASS**.
- M24 Voice UI/Orb: **IMPLEMENTED; MANUAL PASS**.
- M24.1 Hands-Free Voice: **IMPLEMENTED historically; MANUAL PASS historically; superseded/removed from current UX**.
- M24.1.1 VAD hotfix: **IMPLEMENTED historically; MANUAL PASS historically; superseded with hands-free capture**.
- Current push-to-talk replacement: **IMPLEMENTED / NOT VALIDATED** in the status sources.
- Per-user Web/Telegram voice selection: **IMPLEMENTED / NOT VALIDATED** in the status sources.

Repository risk: the local Gemini/ElevenLabs provider fixes described under Working tree are not in `origin/main`. Live provider calls were prohibited, so the committed STT request shape and curated voice availability were not externally validated.

## Telegram Voice Replies

Status: **IMPLEMENTED; MANUAL PASS**. Owner previously confirmed a live native Telegram voice bubble.

Actual path:

- `UserChannelPreferenceService` stores/reads `user_channel_preferences` by `(user_id, channel=telegram)`.
- Modes are `text`, `voice`, and `auto`; enum default is `text`.
- `text`: always text delivery.
- `voice`: attempts voice for suitable responses from either inbound modality.
- `auto`: voice inbound attempts voice; text inbound stays text.
- Confirmation prompts force text because Telegram markup/action semantics must be preserved.
- `TelegramReplyDeliveryService` evaluates suitability and length/code/table bounds.
- It reuses `SpeechSynthesizer` / `TextToSpeechManager` and passes the current user's resolved Voice ID.
- ElevenLabs MP3 is accepted directly; OGG/Opus and M4A/AAC compatible outputs are also recognized.
- `TelegramNutgramDmOutbound` delegates native multipart `sendVoice` to `TelegramBotManager`.
- Canonical assistant text is already persisted before delivery.
- Unsuitable response, unconfigured TTS, incompatible/oversized audio, TTS exception, temp failure, or Telegram send failure falls back to one text send.
- Outbound audio is written under private `voice-outbound/telegram/{userId}/...` and deleted in `finally`.
- `jarvis:voice:cleanup-temp` purges stale `voice-temp` and `voice-outbound` files.

Only the original native voice-reply path is Owner-confirmed. Per-user selected voice propagation is covered by committed tests but remains **IMPLEMENTED / NOT VALIDATED** as a production user-choice flow.

## Telegram Voice Input

Status: **IMPLEMENTED / NOT VALIDATED**. No Owner live inbound-voice confirmation is recorded.

Actual path:

- Only paired private-DM Nutgram `MessageType::VOICE` / `Message.voice` enters STT.
- `TelegramUpdateHandler::handlePairedVoice` maps `file_id`, `file_unique_id`, `message_id`, duration, MIME, file size, and occurrence time.
- `TelegramNutgramVoiceDownloader` calls Nutgram `getFile($fileId)` then `downloadFile($file, $absolutePath)`.
- It reuses `SpeechToTextManager` and the configured Gemini STT provider; there is no Telegram-specific model and no `voice_sessions` row.
- Transcript is trimmed and then passed to the normal `ConversationTurnService`.
- Persisted inbound body is the transcript. Metadata includes `modality=voice`, `source=telegram`, resolved MIME, and duration. `file_id` is not persisted.
- `ChannelContext.inboundModality` is explicitly `voice`, enabling `auto` mode voice-in → voice-out.
- Idempotency checks `(channel, conversation_id, channel_message_id)` before download/STT.
- Duplicate with an assistant child reply is skipped. A persisted inbound without assistant reply resumes from stored text without repeating STT. Committed unit tests cover duplicate-with-assistant skip, not the resume-without-assistant path.
- Database also has a unique index on `(channel, conversation_id, channel_message_id)`.
- Application limits are 30 seconds and 2,000,000 bytes; Telegram API ceiling is recorded as 20,000,000 bytes.
- Duration/file-size preflight occurs before download where metadata exists; actual downloaded bytes are checked again.
- MIME resolution supports Telegram OGG/Opus aliases without ffmpeg.
- Empty audio/transcript, unsupported format, limits, download errors, missing STT, timeout/rate/provider errors return short text and do not create a bogus AI turn.
- Temporary inbound bytes are deleted in `finally`; stale cleanup is scheduled.
- Group voice behavior is unchanged: group mapper persists `[voice]`, with no STT and no automatic personal reply.
- Video notes, arbitrary audio/music, documents, and group STT are out of scope.

The inbound path has committed unit coverage, but tests were not rerun in this production audit and no live Telegram/Gemini call was made.

## Reminders

Current status: Core/model/tool/scheduler/Telegram delivery and Workspace list/cancel UI are **IMPLEMENTED**. Creation and delivery remain Telegram-dependent. Workspace panel visibility remains a documented **KNOWN BUG**. Recurrence is schema-only.

Actual implementation:

- `reminders` has `user_id`, provenance, text, UTC `run_at`, timezone/original local time, status, delivered/cancelled timestamps, nullable `recurrence_rule`, error, and metadata.
- Production has 13 reminders, all `delivered` at audit time.
- `CreateReminderTool` is available to active users with `reminders`; identity comes from tool context.
- Recurrence arguments are explicitly rejected with `unsupported_recurrence`.
- `ReminderService` parses local wall time, persists UTC, scopes list/count/cancel by `user_id`, and allows cancellation only for scheduled/processing records.
- `ReminderDispatchService` claims due rows and delegates to `ReminderDeliveryService`.
- Delivery is Telegram `sendMessage` only.
- Disabled users cause cancellation; missing Telegram identity retries and eventually fails.
- Scheduler runs dispatch every minute with overlap protection.
- Both owner and regular user capability sets include reminders.
- Routes exist for `GET /{jarvis|chat}/reminders` and `POST /{jarvis|chat}/reminders/{id}/cancel`.
- `PersonalWorkspace` renders a Bell when `capabilities.reminders` is true and mounts `RemindersPanel`.
- The header control is icon-only (`aria-label="Напоминания"`), not a labeled «Напоминания» button. Docs (`CLIENTS/WEB_WORKSPACE.md`) still describe a labeled header.
- A second labeled «Reminders / Open panel» entry exists only in the owner context drawer (`capabilities.ownerContext`). Regular `/chat` users have only the Bell.
- `JarvisWorkspaceController` supplies `capabilities.reminders` and `activeReminderCount`.
- The production bundle contains the reminders panel labels.

Answers to the required questions:

1. **Can a reminder currently be created without Telegram?** No.
2. **Where is it blocked?** `ReminderService::assertCanCreate()` calls `ChannelIdentity::findTelegramForUser()` and throws `ReminderException('telegram_not_connected')` before `Reminder::create()`. `ConversationContextBuilder` and `AiFailureFallback` also tell the model/user that Telegram is required.
3. **Why can the panel be invisible?** Capability gating is not the cause: owner and regular users both receive `capabilities.reminders`. Routes, the Bell, `RemindersPanel`, and the deployed bundle exist. The live symptom still has no authenticated browser root cause. Concrete UX evidence that can explain Owner reports: the header control is icon-only, and regular users have no second labeled entry because the context-drawer reminders block is owner-only. Stale session/deploy remains possible until live reproduction. Keep **KNOWN BUG**.
4. **What exists but is not working/confirmed in UI?** The Bell/panel, own-reminder active/history JSON, active badge, Telegram warning, “create in chat” action, and own-reminder cancellation are implemented. Owner reported the panel itself not visible, so none of those panel interactions are MANUAL PASS.
5. **What is required for M25U.3.1?** Reproduce/fix panel visibility; remove the Telegram identity precondition from creation; preserve reminder rows as Core objects; keep own list/cancel; make Telegram delivery conditional rather than the existence condition; define how due no-Telegram reminders remain visible in Web without being mislabeled as a failed reminder; add focused tests and then validate owner and regular-user flows.
6. **Is a migration required?** Not strictly for a minimal implementation: the existing table can persist channel-independent reminders and `metadata` can carry transitional delivery information. A normalized multi-channel delivery-attempt/outbox table would require a migration, but that is a broader Notification Center/Web Push design and is not mandatory for the documented M25U.3.1 scope.
7. **Can existence be detached without breaking current Telegram delivery?** Yes. Keep `ReminderService::create()` channel-neutral, retain the existing Telegram delivery adapter for linked users, and make dispatch choose/skip adapters without changing the reminder's ownership or canonical schedule. Existing linked users can continue through the same Telegram sender. The important change is not to mark the Core reminder itself failed merely because no Telegram adapter exists.

## Onboarding / personalization

Status: **IMPLEMENTED; MANUAL PARTIAL**.

Implemented:

- `user_assistant_profiles` has unique `user_id`, `assistant_name`, `personality`, `interaction_style`, `about_user`, onboarding status/step/conversation, and timestamps.
- States are `not_started`, `in_progress`, and `completed`; structured steps cover name, personality, interaction style, about-user, and summary.
- `AssistantProfileService` lazily resolves a user's profile, creates/owns a normal «Знакомство» conversation, advances steps, validates required completion fields, and preserves owner identity as Jarvis.
- Tools: `get_assistant_profile`, `update_assistant_profile`, `complete_assistant_onboarding`.
- Tools derive identity from `ToolExecutionContext`; they do not accept arbitrary `user_id`.
- `ConversationContextBuilder` injects structured Assistant identity separately from User General Prompt and Memory.
- Onboarding-only instructions are added only for the owned onboarding conversation while in progress.
- Workspace displays assistant presentation name and onboarding entry/continue action.
- General Prompt remains `user_ai_settings.general_prompt`; it is not used as the assistant-profile storage.
- Personal TTS voice is another separate preference on `users.voice_id`.

Production aggregate: three persisted profiles, all `completed`, for four active users. A user without a row is handled lazily as `not_started`; aggregate counts do not identify users and were not inspected for PII.

Validation:

- Owner confirmed the onboarding entry/«Знакомство» appears: **MANUAL PARTIAL**.
- Full conversational collection, tool updates, completion, profile persistence, later prompt injection, and profile-update E2E: **IMPLEMENTED / NOT VALIDATED**.
- No additional confirmed onboarding bug is recorded beyond incomplete manual validation.
- `startOnboarding()` catches greeting-generation failures, so the route can still open an onboarding conversation even if the greeting was not generated; this is resilience, but it means an empty opening conversation is possible and should be included in validation.

## Memory / Storage / Web Research

Memory:

- Personal Memory Engine models, sources, revisions, profile, summaries, topics, analysis runs, retrieval, writer, and background turn dispatcher are **IMPLEMENTED**.
- Context assembly is bounded by `ContextBudgetManager`; this is **IMPLEMENTED**.
- Production aggregates: 35 memories, 58 sources, 10 revisions, 83 topics, 6 conversation summaries.
- Memory analysis runs: 83 completed and 34 failed.
- The same 34 failures remain in `failed_jobs` on queue `memory`; this is an operational backlog requiring diagnosis, not evidence that all Memory is broken.
- Memory/Context Budget has no separately recorded full Owner MANUAL PASS in the requested status sources.

Storage and uploads:

- Ephemeral image attachments, lifecycle/purge command, persistent stored files/chunks, message-file links, text extraction/search/read tools, ownership checks, and owner Storage page are **IMPLEMENTED**.
- Owner image upload + Gemini vision: **MANUAL PASS**.
- Owner text-file upload + persistent Storage retrieval through chat: **MANUAL PASS**.
- Production: one stored file in `ready`; one ephemeral attachment currently has `summary_status=failed`.
- Screenshot summarization/purge and destructive delete were explicitly not claimed as MANUAL PASS.

Web Research:

- Instance provider settings, Gemini Google Search, Tavily option, `search_web`, `fetch_web_page`, SSRF controls, budgets, and user capability exposure are **IMPLEMENTED**.
- Owner Gemini Google Search scenario: **MANUAL PASS**.
- Tavily and `fetch_web_page` as distinct live checks: **IMPLEMENTED / NOT VALIDATED**.

## Projects / Groups

Projects:

- Owner-only project model/service/policy/UI and links to conversations, topics, memories, and Telegram groups are **IMPLEMENTED**.
- Production contains one active and one archived project.
- No full Owner MANUAL PASS is recorded: **IMPLEMENTED / NOT VALIDATED**.

Telegram Groups:

- Discovery/membership, participant/message persistence, owner UI, outbound group messaging, analysis runs, knowledge/source/revision persistence, search tool, policies, and project linking are **IMPLEMENTED**.
- Personal users do not receive group capabilities or group knowledge.
- Voice messages in groups remain placeholders and do not invoke STT.
- Production has two connected and two left groups.
- Analysis aggregates: three completed and four failed runs.
- Full group campaign remains **IMPLEMENTED / NOT VALIDATED**.

## Google / GitHub integrations

Google:

- Owner-only OAuth/account framework, encrypted credentials, Calendar and Gmail services/tools, scopes, confirmations for risky writes, and connection UI are **IMPLEMENTED**.
- Google Drive is not implemented.
- Current `integration_accounts` table has zero rows, so no production Google account is currently represented as connected.
- Combined Google Calendar/Gmail live campaign: **IMPLEMENTED / NOT VALIDATED**.

GitHub:

- Owner-only OAuth/account framework, encrypted credentials, repository/content/issues/pulls/actions tools, capability gating, and confirmation policy are **IMPLEMENTED**.
- Current `integration_accounts` table has zero rows, so no production GitHub account is currently represented as connected.
- Combined GitHub live campaign: **IMPLEMENTED / NOT VALIDATED**.

Gemini Google Search Web Research is separate from Google OAuth integration; its recorded MANUAL PASS does not validate Calendar/Gmail OAuth.

## Manual validation matrix

- M23 Voice Runtime — **IMPLEMENTED; MANUAL PASS** as part of Voice E2E.
- M23.2 Gemini STT — **IMPLEMENTED; MANUAL PASS**.
- M24 Voice UI/Orb — **IMPLEMENTED; MANUAL PASS**.
- M24.1 Hands-Free Voice — **historically IMPLEMENTED; historical MANUAL PASS; now superseded/removed**.
- M24.1.1 VAD hotfix — **historically IMPLEMENTED; historical MANUAL PASS; now superseded/removed**.
- Current push-to-talk-only Voice — **IMPLEMENTED / NOT VALIDATED**.
- Per-user TTS voice — **IMPLEMENTED / NOT VALIDATED**.
- M25U.1 shared Personal Workspace — **IMPLEMENTED; MANUAL PASS** for core workflow.
- M25U.2 user administration/isolation — **IMPLEMENTED; core MANUAL PASS; hostile A/B campaign NOT VALIDATED**.
- M25U.3 onboarding + reminders panel — **IMPLEMENTED; MANUAL PARTIAL**; reminders panel **KNOWN BUG**.
- M26D documentation realignment — **COMPLETED documentation milestone**; no runtime validation applies.
- Telegram Voice Replies — **IMPLEMENTED; MANUAL PASS**.
- Telegram Voice Input — **IMPLEMENTED / NOT VALIDATED**.
- Reminder Engine with Telegram create/delivery — **IMPLEMENTED**; historical production rows show delivery, but no broader panel milestone PASS.
- Reminder creation without Telegram — **NOT IMPLEMENTED**.
- Reminder recurrence — schema field only; **DEFERRED**.
- Owner image upload + Gemini vision — **MANUAL PASS**.
- Owner persistent text-file Storage flow — **MANUAL PASS**.
- Owner Gemini Google Search — **MANUAL PASS**.
- Memory Engine — **IMPLEMENTED**.
- Context Budget — **IMPLEMENTED**.
- Projects — **IMPLEMENTED / NOT VALIDATED**.
- Telegram Groups/full analysis campaign — **IMPLEMENTED / NOT VALIDATED**.
- Google Calendar/Gmail — **IMPLEMENTED / NOT VALIDATED**.
- GitHub — **IMPLEMENTED / NOT VALIDATED**.
- Desktop/Tauri/tray/hotkey — **CANCELLED**.
- Mobile companion — **DEFERRED**.
- Versioned Client API — **DEFERRED** until a real non-Web client requires it.
- Tasks, Notification Center, Web Push, Daily/Weekly Brief, proactive engine — **DEFERRED / PLANNED**, not implemented.

## Known bugs

1. **Reminder panel visibility:** Owner reports the panel is absent in the real workspace. Code, routes, capability props, and built JS contain it. Root cause is unconfirmed; the most concrete code/docs mismatch is icon-only Bell plus no labeled second entry for regular users.
2. **Reminder existence still Telegram-gated:** `ReminderService::assertCanCreate()` rejects users without Telegram before persistence.
3. **Repository/runtime drift:** production checkout has uncommitted Voice provider and dependency/rules changes. A clean `origin/main` deployment omits them.
4. **Committed Gemini STT request-shape risk:** `origin/main` can encode empty `audioTranscriptionConfig` as `[]`; the local uncommitted fix changes it to `{}`. No live Gemini test was allowed.
5. **Uncommitted ElevenLabs fallback:** selected-voice diagnostic/fallback behavior exists only locally, not in GitHub. Per-user curated voices therefore need live validation against the actual ElevenLabs account/catalog.
6. **Failed async work retained:** 34 failed Memory jobs/runs, four failed group analysis runs, and one failed attachment summary are present in aggregate production state. Payloads/content were not inspected, so causes are not asserted.
7. **Onboarding E2E incomplete:** entry is confirmed, but profile collection/completion/injection has not received Owner MANUAL PASS.
8. **Stale capability unit test:** `UserCapabilitiesTest` denies regular-user `storage`, `web_research`, and `voice` even though the product capability set includes them.

## Documentation drift

Files were inspected but not changed. Drift found:

- `CURRENT_STATE.md` still identifies its snapshot as M26D and its Git section says HEAD is the Telegram Voice Input commit; actual fetched HEAD at audit start is `b099107`.
- `CURRENT_STATE.md` row counts are stale: it records two users, nine conversations, 145 messages, one assistant profile, and 11 voice sessions; current aggregates are four users, 12 conversations, 261 messages, three profiles, and 24 voice sessions.
- `CURRENT_STATE.md` places push-to-talk and personal voice bullets inside a “PASS — Voice pipeline” block, while `ROADMAP.md` and `IMPLEMENTATION_PLAN.md` correctly call these newer changes only IMPLEMENTED. This can overstate their manual validation.
- `CHANNELS.md` and `TELEGRAM_VOICE.md` say “Web Voice: MANUAL PASS” without distinguishing the historically validated hands-free pipeline from the newer unvalidated push-to-talk UI.
- `CHANNELS.md` §Future / cancelled still lists Telegram Voice Input/Replies even though they are shipped current surfaces.
- `VOICE_ARCHITECTURE.md` §Telegram voice delivery header says IMPLEMENTED / NOT VALIDATED for the whole section, while the same section body correctly splits Replies MANUAL PASS vs Input IMPLEMENTED / NOT VALIDATED.
- `ROADMAP.md` still lists “better short-pause policy” under future Voice work although current capture is explicit push-to-talk. It should be framed only as a future, separately scoped return to hands-free detection.
- `ROADMAP.md` Phase A includes omit Telegram Voice Replies/Input, `user_channel_preferences`, and the Sep-2026 AI fallback hardening as delivered items.
- `IMPLEMENTATION_PLAN.md` correctly adds current push-to-talk and per-user voice rows, but the M24.1/M24.1.1 rows remain visually “completed” without saying in those rows that the shipped hands-free UX is now superseded.
- `IMPLEMENTATION_PLAN.md` §D still lists Telegram Voice Replies/Input as deferred strategic items even though they also appear in §A Completed.
- `IMPLEMENTATION_PLAN.md` still says “Do not implement in M26D” for M25U.3.1 after M26D is complete.
- `DECISIONS.md` is internally usable: ADR-256 explicitly supersedes automatic-capture portions of ADR-220/221/223/224, and ADR-257 supersedes instance-only Voice ID clauses in ADR-248/254. Older ADR bodies remain historical and must be read with their status notes.
- `DECISIONS.md` ADR-253 still sequences Telegram Voice Replies after M25U.3.1, but those replies shipped first (`a8e05d5`).
- `DECISIONS.md` ADR-250 still describes `auto` as a recommended default candidate; shipped default is `text`.
- `REMINDERS.md` accurately separates current Telegram-gated code from target architecture. `CLIENTS/WEB_WORKSPACE.md` still describes a labeled header «Напоминания»; the UI is an icon-only Bell.
- `MEMORY_ARCHITECTURE.md` still frames a full memory engine as future Phase 2, while turn analysis/summaries/topics/memories/retrieval are already wired.
- `INTEGRATIONS.md` still implies ordinary users lack `web_research`; `UserCapability::forRegularUser()` includes it.
- `ASSISTANT_PERSONALIZATION.md` reflects per-user cross-channel TTS voice and General Prompt separation.
- `USERS_AND_CABINET.md` reflects push-to-talk and personal voice. Its broad wording that ElevenLabs is owner-only should be read as provider/integration administration; ordinary users now select a curated voice.
- `TASKS_AND_PRODUCTIVITY.md` is consistent: Tasks, Notification Center, Web Push, and proactive features remain planned, not shipped.
- Desktop cancellation and Mobile deferral are consistently recorded.
- The current next executable milestone remains **M25U.3.1 — Web Reminders without Telegram**.
- The prior Work Report was a Telegram Voice Input implementation report with appended later notes, not a current project snapshot. This audit replaces it.

## Recommended next milestones

1. **M25U.3.1 — Channel-independent reminders**
   - Why: Web users cannot create reminders without Telegram, and the panel has a recorded live visibility bug.
   - Blockers: authenticated reproduction of the panel issue; decision for due reminders with no delivery adapter.
   - Dependencies: current Reminder model/tool/scheduler/panel and Telegram delivery adapter.
   - Scope: remove create gate, preserve Core reminder lifecycle, fix panel visibility (including labeled/user-visible entry beyond the icon-only Bell), keep own list/cancel, make Telegram optional, add focused tests including panel HTTP routes.
   - Migration: not required for the minimal milestone; required only if normalized delivery attempts/channels are introduced.
   - Live validation: required for owner and regular user, with and without Telegram.

2. **Reconcile production checkout with `origin/main`**
   - Why: uncommitted Gemini/ElevenLabs fixes and Boost dependency files make GitHub an incomplete deployment source.
   - Blockers: decide which local changes are intentional; review provider error logging for secret/PII safety.
   - Dependencies: none beyond the current dirty diff.
   - Scope: separately review, test, commit intentional changes or explicitly discard them; restore a reproducible clean checkout.
   - Migration: no.
   - Live validation: Gemini STT and ElevenLabs selected/fallback voice checks are required only after approved commits.

3. **Current Voice and Telegram Voice acceptance**
   - Why: the pipeline is historically validated, but push-to-talk, per-user voice, and Telegram Voice Input do not have recorded Owner PASS.
   - Blockers: stable committed provider code and available test accounts/devices.
   - Dependencies: milestone 2 for reproducible provider behavior.
   - Scope: Web desktop/mobile PTT, autoplay/output selection, two users choosing different voices, Telegram text/voice/auto, inbound limits/empty audio/idempotency.
   - Migration: no; `users.voice_id` is already ran.
   - Live validation: required.

4. **Async reliability triage**
   - Why: production retains 34 failed Memory jobs/runs, four failed group analyses, and one failed attachment summary.
   - Blockers: inspect sanitized exception classes/codes and determine whether failures are historical or reproducible.
   - Dependencies: queue/provider configuration; no product redesign.
   - Scope: classify failures, fix only confirmed causes, define retry/cleanup policy, verify new runs while preserving old audit data.
   - Migration: no migration is currently indicated; reassess only if root-cause evidence points to schema.
   - Live validation: required for one bounded Memory run, one group analysis, and one attachment summary after fixes.

5. **Complete onboarding and isolation validation**
   - Why: onboarding is only MANUAL PARTIAL and cross-user isolation has not had the prepared A/B campaign.
   - Blockers: two safe test users and an approved non-destructive checklist.
   - Dependencies: existing profiles/tools/workspaces and ownership services.
   - Scope: full «Знакомство» completion, later identity injection, General Prompt separation, owner impersonation exit, cross-user 404/403 checks for conversation/storage/voice/profile/reminder resources.
   - Migration: no.
   - Live validation: required; no hostile/destructive production actions.

## Safety checks performed

Performed:

- `git fetch origin`.
- Branch, local/remote SHA, tracking, ahead/behind, remote URL, status, porcelain status, untracked files, diff stats, and last 20 commits.
- Read-only `php artisan route:list --except-vendor`.
- Read-only `php artisan migrate:status`.
- Read-only `php artisan schedule:list`.
- Read-only `php artisan db:show --counts`.
- Read-only `php artisan db:table` for critical ownership, Voice, reminder, profile, preference, message, storage, attachment, memory, project, and integration schemas.
- Aggregate-only production queries for role/status, reminder status, onboarding status, Voice settings/session status, channel preferences, integration count, queue failures, Memory/group analysis status, attachments, stored files, projects, and groups. No message bodies, prompts, credentials, personal names, email addresses, Telegram IDs, or provider payloads were read.
- Runtime config inspection for database, queue, and non-secret Voice limits/provider defaults.
- Static route/service/model/frontend inspection.
- Static production bundle string inspection.
- `php -l` over all tracked PHP files: passed.
- `git diff --check`: passed before commit.

Not performed:

- No application code changes.
- No documentation changes outside this Work Report.
- No migration creation or execution.
- No production database writes.
- No PHPUnit/Artisan tests against the production database.
- No live AI, Telegram, Gemini, ElevenLabs, Google, GitHub, Tavily, or browser acceptance test.
- No destructive or hostile isolation test.
- No queue retry, failed-job deletion, reminder dispatch, cleanup command, build, dependency install, or service restart.
- No pre-existing working-tree change was staged, discarded, or committed.
