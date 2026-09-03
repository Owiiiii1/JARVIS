# Каналы

Канал — адаптер. У него нет своей памяти, своего prompt и своего LLM. Один Jarvis Core. Личные каналы делят один **personal** memory context. Telegram-группы — отдельная область ([TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md)).

```
Native event → Adapter.normalize → Core (Conversation Engine | Groups module) → Adapter.render
```

Telegram adapter один: и личные чаты, и группы. После normalize Core смотрит `chat_kind`.

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

Идентичность: внешний id канала связывается с `users` через `channel_identities`. Один человек — один `user_id`, несколько identity.

---

## Telegram — Phase 1

Первый канал. **Не ядро приложения.** ADR-002.

### Роль

- receive message (webhook или long polling — `TBD` на реализации; ядро безразлично);
- различить direct vs group/supergroup;
- normalize (включая group metadata и sender);
- передать в Jarvis Core (DM → Conversation Engine; группа → Groups module);
- для DM: получить response и отправить;
- для группы: по умолчанию не отвечать; исходящие из админки — тот же adapter → Bot API.

### Что запрещено

- вызов LLM из `TelegramWebhookController` / Nutgram handlers напрямую;
- вызов Bot API из React/Inertia;
- хранение «telegram-only» истории, которую Core не видит;
- отдельный prompt «для бота»;
- автоответ во все группы.

### Что допустимо в адаптере

- проверка webhook secret;
- маппинг `from.id` → personal identity; `chat_id` группы → `telegram_groups` (создаёт Core, не форма админки);
- нарезка длинного ответа под лимит Telegram;
- фильтрация шума канала; служебные апдейты групп (`my_chat_member`, edited) передавать в Core, если они меняют status/raw — продуктовые правила `TBD`.

Настройки бота (token, webhook URL) живут в конфигурации и админке, читаются адаптером, не дублируют AI settings.

Личный разговор в Telegram после Phase 3 виден в приложениях как те же **personal** conversations. Группы — модуль [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md); основной просмотр — админка (`TBD`, дублировать group UI в mobile не обязательно).

---

## Mobile — Phase 3

Планируемое мобильное приложение. Минимальный клиент, не отдельный продукт.

### Функционал

- authentication (`TBD` схема);
- текстовый чат;
- история;
- голосовое общение (микрофон → API/voice session → тот же engine);
- статус Jarvis (онлайн, печатает, слушает — `TBD` точный набор).

### Чего нет

- локальной копии memory engine;
- своего выбора модели;
- офлайн-AI как источника истины (кэш UI — можно, source of truth — сервер).

---

## Desktop — Phase 3

Та же роль, что Mobile:

- текстовый чат;
- голосовое общение;
- быстрый доступ к Jarvis;
- возможный background / tray mode в будущем (`TBD`).

Desktop и Mobile используют API того же Jarvis Core. ADR-006.

---

## Будущие каналы

Потенциально: web widget, Slack, почта, и т.д. Тот же адаптерный контракт. Не проектировать их сейчас детально.

---

## Голос как канал или как modality?

Голос — **modality** поверх conversation engine, не отдельный ассистент. Транспорт может быть mobile, desktop или (позже) телефон. После STT это обычное сообщение. ADR-008, принцип 11 в [PROJECT.md](PROJECT.md).

---

## Единая история

| Событие | Результат |
| --- | --- |
| Пишет в Telegram DM | personal messages + тот же user |
| Пишет в Telegram-группу | group raw history; бот молчит (дефолт) |
| Открывает mobile | видит те же **личные** conversations |
| Говорит с desktop | новая personal message |
| Меняет Conversation AI в админке | личные каналы на следующем ходе |
| Меняет Analysis AI | следующие analysis jobs, не тон DM |

Политика «один active conversation vs много тредов» — продуктовая, `TBD`. Хранение должно допускать несколько conversations на пользователя с первого дня.
