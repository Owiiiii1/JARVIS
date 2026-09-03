# Cursor work report — Milestone 4

Date: 2026-09-03  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `e46091ad244c5abf8046a0ea61df30dda24e6a32` (`feat: add conversations and Telegram chat selector`) |
| Commit message | `feat: add role-based AI conversation runtime` |
| Working tree before start | clean |

## Backups (pre-migration)

| Item | Value |
| --- | --- |
| Path | `/var/www/jarvis/storage/backups/m4_users_ai_conversations_messages_20260903_165413.sql` |
| Scope | `ai_provider_settings` + `users` + `conversations` + `messages` + `channel_identities` |
| Committed to Git | **no** |

Owner Telegram pairing was not unlinked. Existing chats/messages were not deleted.

## Migrations

1. `2026_09_03_200000_create_ai_role_settings_table`
2. `2026_09_03_200100_create_user_ai_settings_table`
3. `2026_09_03_200200_add_parent_message_id_to_messages_table`

Command: `php artisan migrate --force`

## Role config schema

`ai_role_settings`: `id`, unique `role_key`, `provider`, `model`, `system_prompt`, `parameters` json, `is_enabled`, timestamps.

Seeded rows (all disabled, no provider/model until owner configures):

- `owner_conversation`
- `owner_analysis`
- `user_conversation`

No API keys in this table. Default platform prompts stored in DB via `DefaultRolePrompts` at seed time.

## User prompt schema

`user_ai_settings`: unique `user_id`, `general_prompt` nullable, `overrides` json nullable (unused), timestamps.

Owner and users each have their own row. No per-user model override.

## Provider chat implementations

`AiProviderClient::chat(AiChatRequest): AiChatResponse`. Engine never sees vendor response objects.

| Provider | Chat | Endpoint |
| --- | --- | --- |
| OpenAI | IMPLEMENTED | `POST /v1/responses` (`store: false`); fallback `POST /v1/chat/completions` |
| Anthropic | IMPLEMENTED | `POST /v1/messages` (`anthropic-version: 2023-06-01`) |
| Gemini | IMPLEMENTED | `POST .../v1beta/models/{id}:generateContent` |

Model id is never hardcoded; it comes from `ai_role_settings.model`.

`ai_provider_settings` remains the credential pool (keys, `listModels`, `is_connected`). `is_active` is unused by conversation runtime.

## Resolver

`AiConfigurationResolver`:

- `user.role === owner` → `owner_conversation`
- `user.role === user` → `user_conversation`
- `resolveAnalysis()` → `owner_analysis` only

No `user_id === 1`. No Telegram-specific selection. Analysis is never called from personal DM.

## Context builder

`ConversationContextBuilder` collects:

1. platform/system prompt of the resolved AI config
2. this user's General Prompt if present
3. last 5–40 semantic messages of **this** conversation (default 30)
4. current inbound
5. optional application event text (pairing greeting)

Excluded: other chats, groups, projects, long-term memory, technical `role=system` / `message_type=system` / M3 “Сообщение сохранено…” placeholders.

## Telegram pipeline

Paired normal text:

1. persist inbound (`role=user`)
2. if inbound already existed → **do not** call AI
3. build context + resolve conversation config
4. `AiChatGateway::chat`
5. persist assistant (`role=assistant`, `channel=telegram`, `message_type=text`, `parent_message_id=inbound`)
6. send Telegram

Failure: inbound kept; no semantic assistant row; user gets `Не удалось получить ответ от AI. Попробуйте ещё раз позже.`; technical `role=system` row allowed. Logs provider/model/error class/latency, not API keys or full prompts.

Nutgram handler does not call vendor APIs directly.

## Pairing greeting

After successful pairing: ensure `Основной`, activate, call Conversation AI with application event `Пользователь только что подключил Jarvis. Поприветствуй его и коротко представься.` Greeting stored as `role=assistant` with `metadata.ai.event=pairing_greeting`. No fake user `hello`. Paired `/start` stays static (current chat + menu) and does not spend an LLM turn.

## Retry / idempotency

Same Telegram `message_id` in the same conversation returns the existing inbound and does not start a second AI turn. Assistant replies are linked via `messages.parent_message_id`. Inbound metadata `ai.status` is `pending` / `completed` / `failed`.

## AI Settings UI

Settings → AI is split:

1. **Provider Credentials** — keys, check connection, discovered models. No keys shown in role blocks.
2. **Three role blocks** — enabled, provider (connected only), model (that provider’s available models), system prompt, parameters.

Backend rejects enable if provider is not connected, model is empty, or chat is unimplemented.

Legacy activate/deactivate routes remain; they do not drive runtime.

## User General Prompt UI

- Owner: Admin Profile → General Prompt (separate from platform system prompt)
- User: Cabinet → AI Settings (`/cabinet/ai-settings`), textarea + save, own prompt only

User cannot access admin AI config (403).

## Tests

PHPUnit: 67 passed, 1 skipped. Production DB `jarvis`. No RefreshDatabase / wipe. Temporary users `jarvis-test-*@invalid.local` cleaned by id. Provider HTTP tests use `Http::fake`. Conversation turns use `FakeAiChatGateway` (no billable requests).

Covered: schemas; resolver owner/user/analysis; current-chat context; general prompt included; technical placeholders excluded; inbound before AI; assistant after AI; failure keeps inbound without assistant; duplicate inbound no second AI call; pairing greeting uses user_conversation; user cannot edit another prompt; user cannot access admin AI config.

## Build

`npm run build` succeeded. New chunks: `AiPanel`, `Cabinet/AiSettings`, Profile General Prompt.

## Production counts (after migrate)

| Item | Count |
| --- | --- |
| users | 1 (owner) |
| channel_identities | 1 (owner Telegram `787066206`) |
| conversations | 2 |
| messages | 4 |
| ai_role_settings | 3 (all disabled) |
| user_ai_settings | 0 |

## Provider credentials status (no secrets)

| Provider | API key stored | Connected | Discovered models | `is_active` |
| --- | --- | --- | --- | --- |
| openai | no | no | 0 | no |
| anthropic | no | no | 0 | no |
| gemini | no | no | 0 | no |

Keys were not present before this milestone and were not written or reset. Role configs were not auto-enabled and no expensive model was selected.

## Manual smoke status

Not executed against a live LLM: no provider is connected. After the owner saves a key, checks connection, and enables Owner Conversation AI (and Default User Conversation AI if needed), Telegram normal text should persist inbound, call the selected model, persist the assistant reply, and keep `Основной` / `Тест` contexts separate.

## Known issues

- AI will not answer until the owner connects credentials and enables the matching role configuration.
- `ai_provider_settings.is_active` still exists for backward compatibility; cleanup migration later.
- Cabinet has no full web chat composer; Telegram is the dialogue surface.
- Owner Analysis AI is settings-only; no jobs.
- Tools / groups / long-term memory are not implemented.

## Remaining next milestone

Milestone 6 originally mixed Chat Selector (already in M3) with later cabinet chat. Next product work: richer Cabinet chat UI, memory/summaries, Analysis jobs, tools/integrations, as listed from Milestone 7 onward in `Docs/IMPLEMENTATION_PLAN.md`.
