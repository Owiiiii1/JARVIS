# Database (actual schema)

**Status.** Snapshot 2026-09-05. Source of truth: migrations in `database/migrations/`. Conceptual notes remain useful; this file lists **what exists**.

Engine: MySQL. CRM leftover tables were dropped. Vector DB is not used.

---

## Identity

**users:** `role` owner\|user, unique `access_code` (owner `2000`), `status` active\|disabled, IANA `timezone`, password hash (not the access code).

**user_ai_settings:** unique `user_id`, `general_prompt`, unused `overrides` json.

**user_assistant_profiles:** unique `user_id`; `assistant_name`, `personality`, `interaction_style`, `about_user`, onboarding fields.

**user_profiles:** derived Memory summary (not the assistant profile).

**channel_identities:** Telegram (and future channels) ↔ user; `active_conversation_id`.

---

## Conversations

**conversations:** `user_id`, `kind` direct\|group, title, status, `last_activity_at`.

**messages:** conversation, user, optional `telegram_group_id`, role, channel, body, types, `channel_message_id`, parent, metadata, `occurred_at`.

**message_attachments:** ephemeral/private attachments + lifecycle.

---

## Memory

`conversation_summaries`, `topics`, `message_topic_relations`, `memories`, `memory_sources`, `memory_revisions`, `memory_analysis_runs`.

---

## Reminders

**reminders:** `user_id`, source conversation/message, text, `run_at` UTC, timezone, status scheduled\|processing\|delivered\|cancelled\|failed, `recurrence_rule` (unused on create), errors/metadata.

---

## Storage

`stored_files`, `stored_file_chunks`, `message_stored_files`.

---

## Voice

**voice_settings:** singleton STT/TTS providers, `stt_model`, spoken style, encrypted ElevenLabs key + voice id.

**voice_sessions:** `public_id`, user, conversation, origin, status, providers used, timestamps, error, metadata.

---

## Projects (Owner)

`projects` plus pivots `project_conversations`, `project_topics`, `project_memories`, `project_groups`.

---

## Telegram groups

`telegram_groups`, `telegram_group_participants`, analysis runs, `telegram_group_knowledge` + sources/revisions.

---

## Integrations / tools / settings

`ai_provider_settings`, `ai_role_settings`, `telegram_bot_settings`, `web_research_settings`, `integration_accounts`, `tool_execution_logs`, `tool_confirmations`.

---

## Framework

`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations`.
