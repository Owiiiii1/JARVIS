# Cursor work report — M25U.3 User Personalization + Reminders Panel

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`

No secrets, transcripts, private profile content, or audio.

---

## Starting HEAD

| Item | Value |
| --- | --- |
| `git fetch origin` | done |
| Local / `origin/main` at start | `7d9c83f990f95415b60d3fd8e7e33f27bd9b4f95` |
| Message | `feat: add hands free voice turn detection` |
| M24.1 | **present; not reverted** |
| M23.2 Gemini STT | present (ancestor `00b54e0`) |
| M25U.1 Shared Workspace | present |
| M25U.2 User Administration | present (`72b4e3c`) |
| Working tree at start | clean |

Owner confirmed after M25U.2 (production, not this agent): create user, login, `/chat`, basic requests. Documented as **MANUAL PASS** for those scenarios only.

---

## Schema / backup

Additive migration `2026_09_04_231500_create_user_assistant_profiles_table`.

Pre-migration dump of `users`, `user_ai_settings`, `reminders`, `conversations`, `messages`, and memory-related tables into `storage/backups/` (gitignored). No destructive migration. Existing chats/memory/settings rows not rewritten.

Owner rows seeded: `assistant_name = Jarvis`, `onboarding_status = completed`. Ordinary users: no forced row; missing profile = `not_started`.

---

## Profile schema

`user_assistant_profiles`: unique `user_id`; `assistant_name`; `personality`; `interaction_style`; `about_user`; `onboarding_status` (`not_started` / `in_progress` / `completed`); `onboarding_step`; `onboarding_conversation_id`; started/completed timestamps.

Not vendor config. Not General Prompt.

---

## Onboarding state machine

- `not_started` → **Познакомиться** → `in_progress` + conversation **Знакомство**
- tools update fields when the user explicitly answers
- `complete_assistant_onboarding` only if all four required fields are present → `completed`
- Chat remains usable throughout (no forced block)
- Owner never enters this flow

---

## Onboarding conversation entry

`POST /chat/onboarding` (and `/jarvis/onboarding`): `AssistantProfileService::startOnboarding` then optional `greetOnboarding` (same Conversation Engine). Not a wizard. Not a second engine.

**This agent did not run that greeting (no live AI).**

---

## Tools

Scoped to the conversation user only:

- `get_assistant_profile` (read)
- `update_assistant_profile` (write, no confirmation modal, only provided fields)
- `complete_assistant_onboarding`

Never accept `user_id` from the LLM.

---

## Context / precedence

Platform / role AI → **assistant identity** → User General Prompt → memory / summaries → current conversation.

Onboarding extra instructions only while that conversation is `in_progress`.

---

## Header

- Ordinary user: `assistant_name` or **Assistant**
- Owner: **Jarvis**
- Telegram bot username unchanged; AI uses chosen name
- Voice uses the same profile; TTS Voice ID remains instance setting

---

## Reminders

- Workspace prop: `activeReminderCount` only
- `GET /{jarvis|chat}/reminders` lazy list
- `POST /{jarvis|chat}/reminders/{id}/cancel` — owned scheduled/processing only
- Foreign id → 404
- Create remains chat (`create_reminder`)
- Delivery unchanged (Telegram)

---

## Ownership

Reminders and profile always `user_id` of Auth/conversation user. Impersonation uses that user + existing banner.

---

## Build / static checks

- `vendor/bin/pint --dirty`
- `npm run build`
- `php artisan route:list` (onboarding + reminders)
- `php artisan migrate:status`

**TESTS NOT RUN.** No `php artisan test`, PHPUnit.

**NO LIVE AI / REMINDER / TELEGRAM / VOICE** during this implementation.

---

## Manual validation checklist (NOT RUN)

A. User without profile: Знакомство не пройдено + Познакомиться  
B. Click Познакомиться: Знакомство chat; assistant asks name / character / style / about user  
C. Finish: status completed; header shows chosen name  
D. New chat keeps identity  
E. «Как тебя зовут?» → selected name  
F. «Теперь тебя зовут Альфред» → profile + header  
G. «Будь короче и прямее» → profile updates  
H. About user + Memory remain distinct  
I. Chat reminder appears in panel; local time; cancel own; foreign id denied  
J. Owner still Jarvis; no forced onboarding  
