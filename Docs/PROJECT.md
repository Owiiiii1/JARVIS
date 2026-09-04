# Jarvis — проект

## Назначение

Jarvis — персональный AI-ассистент пользователя. Концептуально вдохновлён J.A.R.V.I.S. из Iron Man: один постоянный собеседник, который знает контекст жизни и работы пользователя и доступен там, где удобно в данный момент.

Это **не** обычный chatbot с обнуляемым контекстом. У **каждого** пользователя формируется **своя** долговременная память Jarvis, общая между его чатами и каналами. Чужой context не подмешивается. Разговор, начатый в Telegram, доступен в кабинете, mobile и desktop **этого же** user. Голос — тот же ассистент, а не отдельный продукт.

Jarvis должен:

- общаться с пользователем текстом и, позднее, голосом;
- помнить историю взаимодействия;
- накапливать персональный контекст;
- понимать, к какой теме относится текущий разговор;
- извлекать только релевантный контекст, а не всю историю;
- пассивно слушать Telegram-группы, сохранять их историю и позже анализировать её **отдельно** от личной памяти;
- **Owner Space** и независимые **User Spaces** (общие engines, разные scopes и AI configs);
- Owner Conversation AI ≠ Default User Conversation AI; Owner Analysis AI отдельно;
- Telegram и Cabinet делят каталог chats + Chat Selector;
- reminders (Telegram-only delivery) для owner и users; Projects и Google — owner;
- стать постоянно доступным ассистентом через несколько клиентов.

## Что Jarvis не является

- Не набор изолированных чатов с обнуляемым контекстом.
- Не Telegram-бот с AI-логикой внутри адаптера.
- Не отдельный «голосовой ассистент» рядом с текстовым.
- Не админка: Admin Panel — техническое управление, не основной интерфейс общения и не источник решений модели. Owner Personal Workspace — отдельная поверхность (planned).
- Не один «мозг на весь инстанс» и не «только один человек в системе»: owner и users делят Core, не права.
- Не админка для каждого залогиненного: `role=user` не получает Admin Panel.

## Принцип одного ядра

Существует один **Jarvis Core**. Все клиенты — адаптеры:

- Telegram (implemented);
- User Cabinet (implemented, `role=user`);
- Owner Personal Workspace (planned, same repo);
- Desktop App — Tauri 2, repo `Owiiiii1/JARVIS-Desktop` (planned);
- Mobile App — Flutter, repo `Owiiiii1/JARVIS-Mobile` (planned);
- Voice mode over Web/Desktop/Mobile (planned modality).

Ядро владеет пользователями, разговорами, памятью, оркестрацией AI и сборкой контекста. Канал только доставляет нормализованное сообщение и возвращает ответ.

Owner — запись `users` с `role=owner` (не hardcoded id). Telegram pairing: уникальный `access_code` (owner **`2000`**). Код не web-пароль и не создаёт User из чата. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

Telegram-группы — отдельный модуль поверх того же адаптера: discovery, raw history, админ-чат, исходящие от имени бота. Это не personal DM и не автоматическая личная память. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

AI-конфигурация: **Owner Conversation AI**, **Owner Analysis AI**, **Default User Conversation AI**. Не одна модель на owner и users.

Integrations (Google Calendar/Gmail, later GitHub, ElevenLabs) — **owner-only** через Tool Layer. [INTEGRATIONS.md](INTEGRATIONS.md). Исполнение: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Клиенты: [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md).

## Этапы в одном предложении

| Phase | Суть |
| --- | --- |
| 1 | Roles + pairing + owner DM + Chat Selector + Cabinet + User Telegram + Reminders (вехи 1–10) |
| 2 | Структурированная долговременная память и выборочный контекст |
| 3 | Owner Workspace, Client API, Voice, Desktop/Mobile repos к тому же ядру |
| 4 | Естественный непрерывный ассистент, а не схема «вопрос → ответ» |

Подробности: [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md), [ROADMAP.md](ROADMAP.md).

## Архитектурные принципы

1. Один центральный assistant core.
2. Channels не содержат AI-логики.
3. База данных — source of truth для conversations и memory.
4. Raw data отделена от AI-generated interpretations.
5. AI-generated memory может быть пересчитана.
6. Смена LLM provider не должна ломать ядро.
7. Контекст собирается динамически под запрос.
8. Вся история не передаётся модели без необходимости.
9. Ядро не мешает появлению tools/actions, но они не цель Phase 1.
10. Voice — другой интерфейс к тому же Jarvis.
11. Один `user_id` = единый **личный** memory context на всех его устройствах и чатах; не между разными users.
12. История Telegram-групп — отдельная область; в personal memory только с явным provenance.
13. Owner Conversation AI, Owner Analysis AI и Default User Conversation AI — разные configuration domains.
14. User General Prompt правит сам user; не отменяет platform/security. Optional later: per-user model override поверх Default User Conversation AI.
15. Cross-chat: summary-first / raw-on-demand. Telegram выбирает active conversation.
16. Reminders — Core, не Calendar; delivery сейчас Telegram-only.
17. Projects ≠ Topics; group knowledge — только explicit owner tool.
18. Capabilities поверх roles, не россыпь `if role === owner`.
19. Сначала простая рабочая система, затем усложнение memory intelligence.
20. Не over-engineer Phase 1 ради Phase 4, но не hardcode owner по `user_id` и не смешивать access_code с паролем.
21. Исполняемый порядок — [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md), не абстрактные фазы в одиночку.

## Текущее состояние репозитория

В репозитории уже есть Laravel-приложение и Admin Kit (логин, settings, AI provider settings, Telegram settings). Это **операционная оболочка**, а не готовый Jarvis Core.

Документы в `Docs/` описывают **целевую** архитектуру. Реализация функционала по ним — отдельная работа. Сейчас документация не требует менять код приложения.

## Связанные документы

- [ARCHITECTURE.md](ARCHITECTURE.md) — модули ядра, AI, каналов, админки
- [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md) — долговременная память
- [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md) — жизненный цикл сообщения
- [CHANNELS.md](CHANNELS.md) — Telegram / Workspace / Desktop / Mobile / Voice mode
- [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md) — owner Personal Workspace (planned)
- [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md) — versioned client protocol (planned)
- [DECISIONS.md](DECISIONS.md) — ADR-001–095
- [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md) — группы, discovery, админ-чат, анализ
- [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md) — три AI configuration domains
- [DATABASE.md](DATABASE.md) — концептуальная модель, включая telegram_groups
- [ROADMAP.md](ROADMAP.md) — фазы
- [USERS_AND_CABINET.md](USERS_AND_CABINET.md) — spaces, capabilities, pairing, Chat Selector
- [REMINDERS.md](REMINDERS.md) — Core reminders ≠ Calendar
- [PROJECTS.md](PROJECTS.md) — Owner Space контейнеры
- [INTEGRATIONS.md](INTEGRATIONS.md) — tools, confirmation, multi-step
- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)
- [CURRENT_STATE.md](CURRENT_STATE.md) — только факт кода
