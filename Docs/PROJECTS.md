# Projects

Рабочий контейнер **Owner Space**. Не Topic. Обычным Users на MVP **не** нужен и не доступен (`projects` capability = owner).

Project связывает уже существующие сущности **relations**, не копируя raw внутрь.

Пример: проект `JARVIS` может связать conversations, topics, memories. Позже — Telegram groups, group knowledge, GitHub / files / integration resources.

M13 runtime: conversations, topics, memories. `project_groups` **не** создан, пока нет Telegram Groups subsystem (M11/M14).

---

## Relations

```
Project ↔ conversations   (project_conversations)
Project ↔ topics          (project_topics)
Project ↔ memories        (project_memories)
Project ↔ telegram_groups  planned after Groups subsystem
Project ↔ group knowledge  planned after Group Analysis
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

Project context — tool `get_project_context` (capability `projects`). Summary-first: description, attached topics, attached memories, current conversation summaries. Raw других чатов — существующий `search_conversation_history`.

Archived projects не резолвятся как active.

Связано: [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md), [DATABASE.md](DATABASE.md).
