# Каналы

Канал — адаптер. У него нет своей памяти, своего prompt и своего LLM. Один Jarvis Core.

Личный канал работает с **personal** memory **резолвленного** `user_id`. Telegram-группы — отдельная область ([TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md)).

## Current channels / modalities

| Surface | Kind | Status |
| --- | --- | --- |
| Web text | Web Personal Workspace `/jarvis` or `/chat` | PRIMARY |
| Web voice | Modality over the same conversation (`metadata.modality = voice`, `messages.channel = web`) | MANUAL PASS |
| Telegram DM | Adapter — **text** inbound and outbound | IMPLEMENTED |
| Telegram Groups | Owner-only group persist / analysis | IMPLEMENTED / NOT VALIDATED as a full campaign |

## Future / cancelled

| Surface | Status |
| --- | --- |
| Telegram Voice Input (user voice note → STT) | PLANNED / NOT IMPLEMENTED |
| Telegram Voice Reply (`sendVoice` TTS delivery) | PLANNED / NOT IMPLEMENTED |
| Mobile companion | DEFERRED |
| Desktop | CANCELLED |
| Client API as a product | DEFERRED |

Voice is **not** a standalone channel identity. Web Voice is a modality of Web over Conversation Core. Telegram Voice Reply, if built, is **adapter delivery** of the same assistant text — not a new channel-brain. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md).

Admin Panel is **not** a conversation channel.

```
Native event → Adapter.normalize → Core (Conversation Engine | Groups) → Adapter.render
```

Telegram adapter handles DM and groups. After normalize, Core looks at `chat_kind`. Telegram DM and Web Workspace share the **same** `conversations` / `messages` catalog.

---

## Adapter duties

- Accept a native event
- Normalize inbound (text, ids, time)
- Pass to Core
- Receive outbound
- Render in channel semantics

The adapter may know Telegram length limits or a future mobile push payload. It must not know which LLM provider is selected or how retrieval works.

Identity: external channel id ↔ `users` via `channel_identities`. One person → one `user_id`. Two people → two `user_id`s.

---

## Web Personal Workspace

Canonical web client. `channel=web`, `channel_message_id` = client UUID. Telegram and Web messages in one conversation mix chronologically into AI context.

| Role | Route |
| --- | --- |
| Owner | `/jarvis` |
| User | `/chat` |
| Compatibility | `/cabinet` redirects (some cabinet JSON routes still exist) |

Access code is **not** a web password.

Owner Workspace is the owner messenger plus owner chrome (projects, integrations, Storage page, Admin). User Workspace is the same chat product without owner chrome.

## Conversation continuity

The same `conversation_id` continues across Telegram DM and Web. Voice uses that conversation; switching Text ↔ Voice does not mint a new thread.

---

## Telegram

First shipping adapter, still a **secondary** product surface after Web. Pairing via `access_code`. Bot does not create Users. Groups persist without auto-reply.

Reminder **delivery** today uses Telegram. That is a delivery adapter, not a channel-specific reminder engine. Target: reminders exist without Telegram. [REMINDERS.md](REMINDERS.md).

### Telegram DM modalities

**Current**

- Inbound: text only (non-text is rejected for paired DM)
- Outbound: text (`sendMessage`)

**Future (not implemented)**

- Voice input: user Telegram voice note → STT → same Conversation Engine (separate from Web Voice; not implied by Web MANUAL PASS)
- Voice output: assistant text → existing TTS → `sendVoice` (OGG/OPUS likely). Canonical content remains text. [TELEGRAM_VOICE.md](TELEGRAM_VOICE.md)

Telegram remains a **secondary** messaging adapter. Web remains the primary interactive client. Desktop stays CANCELLED.
