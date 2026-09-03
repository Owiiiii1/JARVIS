# AI Provider Architecture

Jarvis не зависит от одного HTTP API и одной модели. Business logic не импортирует vendor SDK.

**Нет** одной Conversation AI на owner и users. Это разные configuration domains. ADR-013 уточняется ADR-034.

Фактический код (M4): runtime source of truth = `ai_role_settings`. `ai_provider_settings` хранит credentials / listModels. Поле `is_active` не определяет conversation model.

---

## Три обязательных logical configurations

| Config | Кто использует | Назначение |
| --- | --- | --- |
| **Owner Conversation AI** | только Owner Space | общение owner, tool calls (Calendar/Gmail/group search/reminders) |
| **Owner Analysis AI** | jobs (any user_id scope) | personal memory extract, conversation summaries; groups/projects later |
| **Default User Conversation AI** | все User Spaces | общение обычных users; **не** наследует Owner Conversation AI |

Каждая:

- provider;
- model;
- system / platform prompt;
- parameters;
- для Owner Conversation — tools availability.

Owner может держать дорогую модель; users — отдельную дешёвую. Никакой User **не** резолвит Owner Conversation config «по умолчанию».

Optional **later**: per-user model override поверх Default User Conversation AI. Не обязателен для MVP.

Analysis AI **не** обслуживает обычный user DM. Background Memory Engine uses Owner Analysis AI as the analysis engine; derived rows stay on the source `user_id`. User A output never becomes User B context.

Слоты later (classification, embeddings, …) выделяются из Owner Analysis без смены engines.

---

## Resolve

```
resolveConversationAI(user):
  if user.role === owner → Owner Conversation AI
  else → Default User Conversation AI
       → optional future per-user override
```

```
resolveAnalysisAI():
  → Owner Analysis AI
  (background memory extract/summaries for any user; result scope is always source user_id)
```

Conversation Engine не знает vendor. Не `if user_id === 1`.

---

## Prompt hierarchy (personal turn)

1. Platform prompt **выбранного** conversation config (owner vs default user);
2. Current local time / timezone;
3. Tool context;
4. **User General Prompt** этого space;
5. Relevant personal memories (labelled block);
6. Compact user profile if present;
7. Relevant summaries of **other** chats of this user (not their raw);
8. Current conversation summary if the chat is longer than the recent window;
9. Current conversation recent/raw + current inbound.

User General Prompt редактирует **сам user** в Cabinet (owner — в своих settings). Не отменяет platform/security.

---

## Tools

Owner Conversation AI: multi-step tool loop в одном turn. [INTEGRATIONS.md](INTEGRATIONS.md). Tools: `create_reminder`, `search_conversation_history`.

User Conversation AI: same reminder + history search. Не Gmail/Calendar/groups.

Порт chat/complete возвращает text **и** tool requests. `one message ≠ max one tool call`. Max 5 tool rounds в Core.

`AiChatRequest` передаёт provider-neutral `ToolDefinition`. `AiChatResponse` возвращает text, zero or more `ToolCall`, finish reason, usage.

**Gemini (production):** function calling обязателен — `tools.functionDeclarations`, `functionCall`, `functionResponse` обратно модели, затем natural-language answer. Conversation Engine не парсит Gemini JSON в ReminderService.

**OpenAI / Anthropic:** chat без tools. `supportsTools=false`. Tool-enabled request им не отправляется молча.

---

## Контракт провайдера

- messages + system + params + optional tool definitions;
- text и tool calls;
- usage/latency;
- ошибки.

Speech: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Voice использует **тот же** conversation config своего space.

---

## Admin UI

Owner Settings → AI:

1. **Provider Credentials** — API keys, check connection, discovered models. Не runtime.
2. Три блока конфигурации (Owner Conversation / Owner Analysis / Default User Conversation): enabled, provider, model, system prompt, parameters.

Нельзя enable configuration, если provider не connected, model пустой, или chat не реализован.

Runtime **не** читает `is_active`.
