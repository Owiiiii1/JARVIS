# Assistant personalization

**Status.** IMPLEMENTED. Owner confirmed onboarding **entry** («Знакомство») — MANUAL PARTIAL. Full onboarding completion / profile-update E2E is **not** MANUAL PASS.

Per-user assistant identity is a **first-class profile**, not concatenated into `user_ai_settings.general_prompt`.

See also: [USERS_AND_CABINET.md](USERS_AND_CABINET.md), [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md).

---

## Separation

| Layer | What it is |
| --- | --- |
| Assistant profile (`user_assistant_profiles`) | Who the assistant is: name, personality, interaction style; compact `about_user` from onboarding |
| User General Prompt | Additional explicit ongoing instructions |
| Memory Engine | Facts/preferences accumulated over time |
| Telegram response mode (`user_channel_preferences`) | How Telegram **delivers** the answer (`text` / `voice` / `auto`) — not who the assistant is |

Do not encode onboarding only in General Prompt. Do not treat `about_user` as a replacement for Memory.

---

## Data

Table `user_assistant_profiles`, unique `user_id`.

Fields: `assistant_name`, `personality`, `interaction_style`, `about_user`, `onboarding_status` (`not_started` / `in_progress` / `completed`), `onboarding_step`, `onboarding_conversation_id`, `onboarding_started_at`, `onboarding_completed_at`.

No vendor/provider config here.

Owner: migration/bootstrap defaults `assistant_name = Jarvis`, `onboarding_status = completed`. Owner is not forced through onboarding. Header stays **Jarvis**.

Ordinary user without a row: treated as `not_started` (lazy create). Chat is **not** blocked.

---

## Onboarding

Conversational, same Conversation Engine, same Personal Workspace. Button **Познакомиться** / **Продолжить знакомство** opens (or creates) a normal chat titled **Знакомство**.

Not a form wizard. Optional: user may keep using ordinary chat.

Structured writes go through Core tools/service (`AssistantProfileService`):

- `get_assistant_profile`
- `update_assistant_profile` — only fields the user explicitly stated; no confirmation modal; never pass `user_id`
- `complete_assistant_onboarding` — requires `assistant_name`, `personality`, `interaction_style`, `about_user`

Onboarding instructions are injected only for that conversation while status is `in_progress`. Raw onboarding transcript is not dumped into every later turn.

---

## Context

Every turn gets a compact **Assistant identity** block (name, personality, interaction style, `about_user`, status) after platform/role AI config and **before** User General Prompt.

Telegram: bot username is infrastructure. Conversation AI identifies itself with the chosen assistant name. No Telegram-specific personality profile.

Voice (Web): same profile. TTS Voice ID remains instance-level provider setting unless a future decision adds per-user voice selection.

**Telegram response mode** (`text` / `voice` / `auto`) is a **channel delivery preference** on `user_channel_preferences`, not personality, not General Prompt, and not the TTS Voice ID. Tools: `get_telegram_response_mode` / `set_telegram_response_mode`. Default **text**. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

---

## Presentation

Ordinary `/chat` header shows `assistant_name` when set, otherwise **Assistant**.

Owner `/jarvis` remains **Jarvis**.

---

## Security

Tools always use the conversation/authenticated user. Impersonation uses that user’s profile with the existing banner. User A cannot read or write User B.
