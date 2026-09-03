# AI Provider Architecture

Jarvis не зависит от одного HTTP API и одной модели. Business logic не импортирует vendor SDK.

**Нет** одной Conversation AI на owner и users. Это разные configuration domains. ADR-013 уточняется ADR-034.

Фактический код: один `is_active` — противоречит целевому. [CURRENT_STATE.md](CURRENT_STATE.md). Замена — Milestone 4.

---

## Три обязательных logical configurations

| Config | Кто использует | Назначение |
| --- | --- | --- |
| **Owner Conversation AI** | только Owner Space | общение owner, tool calls (Calendar/Gmail/group search/reminders) |
| **Owner Analysis AI** | jobs Owner Space | Telegram Groups, summaries, decisions, tasks, memory/project analysis |
| **Default User Conversation AI** | все User Spaces | общение обычных users; **не** наследует Owner Conversation AI |

Каждая:

- provider;
- model;
- system / platform prompt;
- parameters;
- для Owner Conversation — tools availability.

Owner может держать дорогую модель; users — отдельную дешёвую. Никакой User **не** резолвит Owner Conversation config «по умолчанию».

Optional **later**: per-user model override поверх Default User Conversation AI. Не обязателен для MVP.

Analysis AI **не** обслуживает обычный user DM.

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
  (вызов только из owner jobs / tools)
```

Conversation Engine не знает vendor. Не `if user_id === 1`.

---

## Prompt hierarchy (personal turn)

1. Platform prompt **выбранного** conversation config (owner vs default user);
2. Channel / system rules;
3. **User General Prompt** этого space (owner и каждый user свой);
4. Personal context: relevant summaries других чатов, structured memory/profile — **того же** space;
5. Current conversation recent/raw + summary;
6. Current message.

User General Prompt редактирует **сам user** в Cabinet (owner — в своих settings). Не отменяет platform/security.

---

## Tools

Owner Conversation AI: multi-step tool loop в одном turn. [INTEGRATIONS.md](INTEGRATIONS.md).

User Conversation AI: reminders (и later узкий набор). Не Gmail/Calendar/groups.

Порт chat/complete возвращает text **и** tool requests. `one message ≠ max one tool call`.

---

## Контракт провайдера

- messages + system + params;
- text и tool calls;
- usage/latency;
- ошибки.

Speech: [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md). Voice использует **тот же** conversation config своего space.

---

## Admin UI

Owner Settings: три блока (Owner Conversation / Owner Analysis / Default User Conversation). Не «одна активная модель Jarvis».
