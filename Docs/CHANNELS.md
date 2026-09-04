# Каналы

Канал — адаптер. У него нет своей памяти, своего prompt и своего LLM. Один Jarvis Core. Личный канал работает с **personal** memory **резолвленного** `user_id`. Telegram-группы — отдельная область ([TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md)).

Клиенты / каналы:

- Telegram
- User Cabinet (web, `role=user`, `/chat`)
- Owner Personal Workspace (web, `/jarvis` — [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md))
- Desktop (planned — [CLIENTS/DESKTOP_APP.md](CLIENTS/DESKTOP_APP.md))
- Mobile (planned — [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md))
- Voice mode over Web / Desktop / Mobile (modality, not a User Space)

Все используют один `user_id` mapping и один catalog `conversations`. Voice не создаёт второй мозг и не создаёт conversation автоматически.

Admin Panel — **не** канал общения. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

```
Native event → Adapter.normalize → Core (Conversation Engine | Groups module) → Adapter.render
```

Telegram adapter один: и личные чаты, и группы. После normalize Core смотрит `chat_kind`. Telegram DM и Web Cabinet используют **один** каталог `conversations` / `messages` (Milestone 3).

Контракт ядра общий. Набор каналов расширяется без изменения AI Layer.

---

## Общие обязанности адаптера

- Принять нативное событие.
- Нормализовать в inbound (текст, ids, время).
- Передать в Core.
- Получить outbound.
- Отправить пользователю в семантике канала (сообщение, chunk, голос).

Адаптер может знать:

- лимиты длины сообщения Telegram;
- push/foreground desktop;
- микрофон mobile.

Адаптер не может знать:

- какой provider выбран;
- как устроен retrieval;
- как устроены topics.

Идентичность: внешний id канала связывается с `users` через `channel_identities`. Один человек — один `user_id`, несколько identity. Два человека — два `user_id`, даже на одном боте.

---

## Web Cabinet (User Space)

Клиент того же Core и **того же каталога conversations**, что Telegram. `role=user` → cabinet chat UI (`/cabinet/chats/{id}`). Chat + свой General Prompt. Ownership на каждом запросе. Web inbound: `channel=web`, `channel_message_id` = client UUID. Telegram и Web messages в одном conversation смешиваются хронологически и входят в AI context. Access code не для web-login.

Owner Personal Workspace (`/jarvis`) is the owner web messenger. Same catalog and `channel=web` inbound as Cabinet. Guest → login. `role=user` → `/cabinet`. Owner `/cabinet` → `/jarvis`. Admin Panel is not a conversation channel.

## Conversation continuity

Owner (и каждый user в своём space) может:

- начать чат в Telegram;
- продолжить тот же `conversation_id` в Web;
- продолжить голосом на Desktop;
- открыть его на Mobile.

Отдельную voice conversation Core не создаёт, пока пользователь явно не выбрал New Chat.

---

## Telegram — Phase 1

Первый канал. **Не ядро приложения.** ADR-002.

### Роль

- receive message через **webhook** + Nutgram (уже выбранный транспорт; long polling не нужен);
- различить direct vs group/supergroup;
- normalize (включая group metadata и sender);
- передать в Jarvis Core (DM → Conversation Engine; группа → Groups module);
- pairing: `/start` и access_code **до** Core AI;
- Chat Selector: список / выбрать / новый / текущий; писать в `active_conversation_id`;
- для авторизованного DM: persist в **active** conversation → Core → send;
- для группы: persist; не отвечать; исходящие из админки (owner) — тот же adapter → Bot API.

### Что запрещено

- вызов LLM из `TelegramWebhookController` / Nutgram handlers напрямую;
- вызов Bot API из React/Inertia;
- хранение «telegram-only» истории, которую Core не видит;
- отдельный prompt «для бота»;
- автоответ во все группы;
- создание User из неизвестного Telegram;
- вызов Conversation AI до успешного pairing;
- Google/Gmail SDK внутри адаптера.

### Что допустимо в адаптере

- проверка webhook secret;
- lookup `from.id` в `channel_identities`; pairing по `access_code` (owner `2000`);
- `chat_id` группы → Core `telegram_groups` (не форма админки). `chat.type` private → personal DM pipeline; group/supergroup → Groups subsystem (persist-only). Channels ignored. `edited_message` and `my_chat_member` go to Core.
- нарезка длинного ответа под лимит Telegram;
- фильтрация шума канала; служебные апдейты групп (`my_chat_member`, edited) передавать в Core, если они меняют status/raw — продуктовые правила `TBD`.

Настройки бота (token, webhook URL) живут в конфигурации и админке, читаются адаптером, не дублируют AI settings.

Один каталог conversations на space. Telegram переключает active; Cabinet открывает любой. Группы — [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md), owner-only.

Unknown access code does not create a User. Regenerating `access_code` does not unlink the current Telegram identity. Unlink removes only that user’s Telegram `channel_identities` row. A disabled user’s already-linked Telegram messages do not invoke Conversation AI.

Доставка reminders — только этот adapter. Disabled users: `ReminderDeliveryService` skips new assistant/Telegram reminder delivery.

---

## Mobile — planned

Flutter, repo `Owiiiii1/JARVIS-Mobile`. [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md).

Минимальный клиент, не отдельный продукт. Не вызывает Google/Gmail/Calendar напрямую.

### Функционал

- authentication (`TBD` схема, Client API);
- текстовый чат;
- история того же catalog;
- голосовое общение + Orb + transcript;
- tool confirmations;
- статус Jarvis (`TBD`).

### Чего нет

- локальной копии memory engine;
- своего выбора модели;
- офлайн-AI как источника истины (кэш UI — можно, source of truth — сервер).

---

## Desktop — planned

Tauri 2 + React/TS, repo `Owiiiii1/JARVIS-Desktop`. [CLIENTS/DESKTOP_APP.md](CLIENTS/DESKTOP_APP.md).

Та же роль, что Mobile, плюс tray / hotkey / updater later.

Desktop и Mobile используют versioned Client API того же Jarvis Core. ADR-006. Rust/Flutter **не** живут в Laravel repo.

---

## Будущие каналы

Потенциально: web widget, Slack, почта, и т.д. Тот же адаптерный контракт. Не проектировать их сейчас детально.

---

## Голос как канал или как modality?

Голос — **modality** поверх conversation engine, не отдельный ассистент и не отдельный канал-мозг. Транспорт: Web Workspace, Desktop, Mobile (позже телефон). После STT это обычное сообщение в выбранный `conversation_id`. Runtime ≠ Orb UI. ADR-008, [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md).

---

## Единая история

| Событие | Результат |
| --- | --- |
| Пишет в Telegram DM | messages **active** conversation его space |
| Чаты / New Chat в Telegram | тот же каталог, что Cabinet / Workspace / apps; смена `active_conversation_id` |
| Web / Desktop / Mobile тот же id | тот же raw history |
| Voice mode | те же messages; не новый chat |
| Cabinet / Workspace New Chat | пустой raw; summaries других чатов доступны ретриверу |
| Меняет Owner Conversation AI | только Owner Space |
| Меняет Default User Conversation AI | все User Spaces (без per-user override) |
| Меняет User General Prompt | все чаты **этого** space |
