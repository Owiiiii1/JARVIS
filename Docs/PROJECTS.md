# Projects

Рабочий контейнер **Owner Space**. Не Topic. Обычным Users на MVP **не** нужен и не доступен (`projects` capability = owner).

Project связывает уже существующие сущности **relations**, не копируя raw внутрь.

Пример: проект `JARVIS` может связать conversations, topics, memories, Telegram groups, group summaries, decisions, tasks, facts/events, позже GitHub / files / integration resources.

---

## Relations

```
Project ↔ conversations
Project ↔ topics
Project ↔ telegram_groups
Project ↔ memories
Project ↔ group knowledge
```

Один conversation / topic / group может быть связан с несколькими projects, если это полезно.

Не дублировать messages в project.

---

## Чем Project не является

| Не | Почему |
| --- | --- |
| Topic | Topic — классификация смысла; Project — контейнер работы |
| Conversation | Чат живёт отдельно и может быть привязан |
| Group | Группа — канал; project — агрегация |
| Personal memory dump | Memories остаются со своим owner/scope |

---

## Owner personal chat

Owner Conversation AI может опираться на project relations **по запросу** (tool / retrieval), не подмешивая все projects в каждый prompt.

Связано: [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md), [DATABASE.md](DATABASE.md).
