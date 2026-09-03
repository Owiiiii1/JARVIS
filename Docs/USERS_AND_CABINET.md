# Users, Personal Cabinets, User-specific AI context

Модуль пользователей и личных кабинетов. Один **Jarvis Core** для владельца инстанса и для дополнительных пользователей. Различия — roles, permissions, enabled features и settings. Не два backend и не hardcoded special case в AI Core.

Подробности памяти: [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md). Промпты и модели: [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md). Схема: [DATABASE.md](DATABASE.md). Решения: ADR-016–021 в [DECISIONS.md](DECISIONS.md).

---

## Зачем отдельные пользователи

Владелец использует Jarvis как полноценного персонального ассистента (каналы, группы, tools, админка — по permissions).

Дополнительный пользователь может быть просто AI-собеседником. Пример: ребёнок изучает программирование, задаёт вопросы, ведёт несколько независимых чатов, имеет свою историю, свои topics и свой General Prompt.

Каждый пользователь имеет **полностью отдельный AI-контекст**. ADR-016.

Общий backend, общий Telegram Bot, общий provider **не** означают общий context.

---

## Изоляция (критическое правило)

Для каждого пользователя независимо существуют:

- profile;
- conversations;
- messages (личных чатов);
- topics;
- memories;
- summaries;
- AI settings (в т.ч. User General Prompt);
- cabinet access.

Данные пользователя A **не** попадают в context пользователя B автоматически.

Даже если оба используют:

- одного AI provider;
- одну модель;
- один backend;
- один Telegram Bot или другой channel.

Все memory / topic / conversation / context retrieval операции **scoped by `user_id`** (или эквивалентный owner scope). Глобальный поиск памяти без явного scope запрещён.

Telegram group knowledge — отдельный scope, не personal memory чужого или даже своего пользователя без правил provenance. См. ADR-012.

---

## Типы пользователей — не разные ядра

| | Jarvis Owner | Additional user |
| --- | --- | --- |
| Conversation Engine | тот же | тот же |
| Memory Engine | тот же, свой `user_id` | тот же, свой `user_id` |
| AI Layer | тот же | тот же |
| API / Cabinet | тот же контракт | тот же контракт |
| Типичные extras | admin, Telegram Groups, tools, integrations | чаты + профиль; features по permission |

Owner — пользователь той же системы с более широкими permissions. Core не ветвится `if owner { otherBrain }`.

---

## Authentication

Два permission context на одном backend:

| Context | Кто | Куда | Нельзя |
| --- | --- | --- | --- |
| Admin | пользователи с admin permission | Admin Panel | не путать с кабинетом |
| Cabinet | любой активный user | Personal Cabinet (`/cabinet` — точный URL `TBD`) | admin panel, чужие users/chats/memories, group administration |

Схема сессий (один guard / два guard, cookie / token) — `TBD`. Инвариант: обычный user не получает admin routes и не читает чужие ресурсы.

Admin, зашедший в кабинет через impersonation, остаётся в admin audit trail, не «становится» этим user навсегда. ADR-020.

Mobile / Desktop Phase 3 используют те же accounts и тот же ownership, что Cabinet.

---

## Authorization

Все user-facing endpoints проверяют **ownership**, не только id из URL. ADR-021.

Недостаточно: «открой conversation 123, раз ты залогинен».

Нужно: conversation 123 принадлежит текущему `user_id` (или admin с явным privileged action).

То же для messages, topics, memories, user_ai_settings, profile.

Реализация — policies / authorization layer ядра (Laravel policies или эквивалент). Канал и UI не обходят этот слой.

---

## Admin Panel — Users

Раздел **Users**. Главная страница — таблица автоматически существующих аккаунтов (созданных админом или иным onboarding, `TBD`).

Минимальные колонки:

- ID;
- имя;
- email / login;
- статус;
- дата создания;
- последняя активность;
- количество чатов;
- количество сообщений;
- роль / type при необходимости.

Строки кликабельны → **User Card**.

---

## User Card

Центральная административная страница конкретного пользователя.

### Profile

- name;
- email / login;
- статус;
- password reset / change;
- прочие профильные поля.

Пароль **никогда** не отображается и не хранится plaintext.

Администратор может только:

- установить новый пароль (hash);
- инициировать reset;
- либо другой безопасный механизм.

### Быстрые действия

| Действие | Куда | Заметка |
| --- | --- | --- |
| Open Cabinet | impersonated cabinet | ADR-020; не пароль пользователя |
| Chat | админ-список чатов пользователя | read / debug |
| Topics | каталог тем этого user | scope = этот user |
| AI Settings | User General Prompt и model override | не путать с platform AI roles |

Те же экраны могут дублироваться из Settings, если навигация админки так удобнее. Source of truth — записи user, не копия в другом модуле.

---

## Admin: User Chats

Из карточки: раздел **Chats**.

Список conversations пользователя:

- название;
- дата создания;
- последнее сообщение;
- количество сообщений;
- статус / архив.

По клику — messenger view: пузыри, автор (user vs Jarvis), время, хронология, pagination / lazy load.

Это **read / debug / admin** capability. Администратор **не** пишет в чат от имени пользователя по умолчанию. Отдельная функция «ответить как user» — только отдельным ADR, не подразумевается.

Просмотр чатов — privileged. Архитектура должна позволять audit log (кто смотрел). См. Privacy ниже.

---

## Admin: User Topics

Собственный каталог topics пользователя. Тот же механизм structured memory, что у владельца, с обязательным owner scope.

Пример набора у одного user: Programming, Python, School, Minecraft, Personal projects. У другого — другой набор.

Retrieval topics всегда `user_id` / `owner = this user`. Не смешивать с group topics и global topics.

---

## Admin: User AI Settings

Из User Card (и при необходимости из Settings):

- просмотр и правка **User General Prompt**;
- назначенная Conversation (и позже Analysis) configuration: default vs override;
- later — memory behaviour.

Не подменять этим экраном platform Conversation / Analysis roles. Platform defaults живут в глобальных AI Settings. ADR-013, ADR-019.

---

## Personal Cabinet

После авторизации пользователь попадает в **свой** Web Cabinet.

Минимум:

- Chat;
- Profile.

Дальше можно расширять (topics для user, настройки prompt — `TBD`, не обязательно в первом кабинете).

Cabinet — тонкий клиент того же Core, что Telegram и будущие приложения. Не второй Conversation Engine.

---

## Cabinet — Chat

Поведение как у ChatGPT:

- список своих чатов;
- создать новый чат;
- открыть существующий;
- продолжать разговор;
- несколько независимых conversations;
- переименовать;
- архив / удаление — продуктовое решение later (`TBD`).

Каждый новый чат = новая сущность `conversations` с `user_id`.

### Conversation context vs User long-term memory

| Слой | Граница | Новый чат |
| --- | --- | --- |
| Conversation context | raw + working context **этого** чата | пустой |
| User long-term memory | topics / memories / profile **этого** user | сохраняется и может быть подтянута, если релевантна |

Пример: в чате A пользователь сказал, что учит Python. В чате B Jarvis может опереться на это только если Memory Engine сохранил факт в **его** personal memory и retrieval признал его релевантным. Сырые сообщения чата A в чат B не копируются.

**New Chat ≠ New User Memory.** ADR-017.

---

## New Chat

При создании:

1. Новая `conversation` с `user_id` текущего пользователя.
2. Raw history пустая.
3. Сообщения других conversations этого user напрямую не подмешиваются.
4. Context builder добавляет: platform prompt → channel rules → **этот** User General Prompt → релевантные **его** memories/topics → (пустое recent) → current message.
5. Резолв модели: user override или platform default. ADR-019.

---

## Cabinet — Profile

Пользователь редактирует допустимые поля: name, password, базовые account settings.

Смена email/login — по будущей auth policy (`TBD`).

Пароль: смена со знанием текущего (или reset flow). Не показывать hash.

---

## User AI Settings и prompt hierarchy

У каждого пользователя есть персональные AI-настройки. Минимум: **User General Prompt** — отдельный слой, не копия и не замена platform system prompt. ADR-018.

Пример для ребёнка: стиль, возрастная адаптация, обучение программированию, уровень объяснений, ограничения поведения.

### Hierarchy context package

Порядок сборки (сверху вниз, нельзя переставлять так, чтобы user слой отменял platform rules):

1. **Platform / System Prompt** — глобальные правила продукта и Conversation role.
2. **Channel / System Rules** — ограничения канала (Telegram лимиты как инструкции, safety канала, `TBD`).
3. **User General Prompt** — поведение для этого user; действует во **всех** его чатах и личных каналах.
4. **Relevant User Memory** — только этого `user_id`.
5. **Relevant Topics** — только его (или явно запрошенный иной scope, не чужой user).
6. **Conversation Summary / History** — только текущий conversation.
7. **Current Message**.

User General Prompt **не может** снимать критические platform/system rules (safety, изоляция, запрет выдавать чужие данные). Реализация: порядок слоёв + неизменяемый platform блок; user prompt — complementary. Точный enforcement (`TBD`: отдельный system vs developer message).

Platform Conversation prompt один на продукт. User General Prompt — один на пользователя, на все его чаты. Не один prompt «на весь инстанс» как личность всех людей.

---

## AI Model Assignment — inheritance

Не у каждого user обязан быть свой provider.

```
platform default (роль conversation, позже analysis)
  → user override, если задан
  → иначе default
```

Пустой override = platform Conversation Model.

Пример: platform = OpenAI / Model X; User B = Provider Y / Model Z.

Секреты провайдеров остаются platform credentials (или явно выданные user-level refs, `TBD`). User не получает чужие keys через кабинет.

Резолв в AI Layer: `resolve(role, user_id)`. Conversation Engine не выбирает vendor. ADR-019.

---

## Impersonation — Open Cabinet

Админ открывает кабинет пользователя **без** знания пароля. ADR-020.

Концепция:

- отдельная impersonated session (или эквивалентный privileged token);
- в UI явно видно: «вы как User N»;
- кнопка выйти из режима — возврат в админку / свою сессию;
- действие логируется (кто, кого, начало/конец);
- admin не получает password hash в понятном виде и не «логинится формой» с паролем жертвы.

Точный механизм (Laravel impersonate package vs свой guard) — `TBD`. Небезопасная передача пароля запрещена.

В режиме impersonation админ видит кабинет как этот user. Запись сообщений от имени user — по умолчанию **нет** (см. Admin Chats). Если в impersonation разрешат писать как диагностика — отдельное решение и отдельный audit event.

---

## Privacy / Audit

Просмотр чужих чатов и impersonation — privileged admin capability, не обычная функция кабинета.

Архитектура должна позволять логировать:

- просмотр User Card / чатов / topics;
- impersonation start/stop;
- изменение User AI Settings;
- password reset / set;
- другие чувствительные admin actions.

Retention и обязательность логов на старте — `TBD`; место в модели — `admin_audit_logs` (имя не финальное).

---

## Связь с каналами и будущими клиентами

- Telegram DM identity → тот же `user_id`, те же memories, свои conversations (часто один тред с ботом).
- Cabinet / Mobile / Desktop — те же accounts, список чатов этого user, ownership на каждом запросе.
- Telegram Groups — feature permission (обычно owner/admin), не personal cabinet другого user.

---

## Фазы

| Момент | Users / Cabinet |
| --- | --- |
| Phase 1 | Контракты: `user_id` на personal ресурсах; много conversations в схеме; prompt hierarchy и default→override как слоты; Core без `if onlyOwner` |
| После persist (не ждать Phase 4) | Admin Users, User Card, Cabinet Chat/Profile, User General Prompt, impersonation concept |
| Phase 2 | Topics/memories per user; retrieval строго scoped |
| Phase 3 | Mobile/Desktop на тех же accounts и conversations |

Первый подключённый Telegram-аккаунт может быть owner. Это onboarding, не архитектура «в системе бывает только один user».

---

## Что не делать

- Не делать второй AI Core для «простых» пользователей.
- Не класть memories A в prompt B.
- Не обнулять user memory при New Chat.
- Не давать User Prompt перекрыть platform rules.
- Не открывать cabinet по паролю пользователя, показанному админу.
- Не считать id в URL достаточным доступом.
- Не смешивать admin context и cabinet context.
