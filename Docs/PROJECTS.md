# Projects

Рабочий контейнер **Owner Space**. Не Topic. Обычным Users на MVP **не** нужен и не доступен (`projects` capability = owner).

Project связывает уже существующие сущности **relations**, не копируя raw внутрь.

Пример: проект `JARVIS` может связать conversations, topics, memories, Telegram groups. Позже — GitHub / files / integration resources.

M13 runtime: conversations, topics, memories. M11 добавил `project_groups` (relation only). M14: `get_project_context` may return **bounded ACTIVE group-derived knowledge** (summaries / decisions / tasks / event-facts) for attached groups. Raw group history is never copied and never dumped into the tool result. Group knowledge is not written into personal `memories`.

---

## Relations

```
Project ↔ conversations   (project_conversations)
Project ↔ topics          (project_topics)
Project ↔ memories        (project_memories)
Project ↔ telegram_groups  implemented (M11, relation only)
Project ↔ group knowledge  M14 via `get_project_context` (bounded derived rows; not a separate pivot; not personal memory)
```

Один conversation / topic / memory может быть связан с несколькими projects.

Не дублировать messages в project. Memory остаётся `scope=personal` + `user_id`. Pivot — не owner факта.

---

## Чем Project не является

| Не | Почему |
| --- | --- |
| Topic | Topic — классификация смысла; Project — контейнер работы |
| Conversation | Чат живёт отдельно и может быть привязан |
| Group | Группа — канал; project — агрегация |
| Personal memory dump | Memories остаются со своим owner/scope |

Создание Project **не** создаёт Topic с тем же именем и **не** создаёт chat. Attach только explicit в Admin. Автоклассификация сообщений в Project в M13 отсутствует.

---

## Owner personal chat

Owner Conversation AI **не** получает все projects в обычный prompt.

Project context — tool `get_project_context` (capability `projects`). Summary-first: description, attached topics, attached memories, current conversation summaries, compact attached group titles, and bounded ACTIVE group knowledge (`config/projects.php`: `max_group_summaries`, `max_group_knowledge`). Raw других чатов — существующий `search_conversation_history`.

Group-specific questions use `search_group_knowledge` (capability `group_analysis`). Optional `project` argument limits that search to attached groups via `project_groups`. The two tools do not replace each other: project context is whole-project derived context; group search is group knowledge and bounded raw.

Archived projects не резолвятся как active.

Связано: [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md), [DATABASE.md](DATABASE.md).
