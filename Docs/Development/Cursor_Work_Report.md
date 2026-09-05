# Cursor work report — Telegram Voice Input

**Date:** 2026-09-05  
**Commit message:** `feat: add Telegram voice input`  
**Starting HEAD:** `a8e05d5bf8acdbd8bf962cc15099190fc4b36c6a` (`feat: add Telegram voice replies`)

No schema migration. No production DB writes from Cursor. No live Telegram / Gemini / AI / ElevenLabs calls.

---

## Product

Paired Telegram DM voice note → existing STT layer → existing `ConversationTurnService` → existing `TelegramReplyDeliveryService`.

Not a second AI. Not `voice_sessions`. Not a Telegram-specific conversation engine.

---

## Actual Telegram voice object

`Message.voice` only (Nutgram `MessageType::VOICE`).

Used fields: `file_id` (download during this job), `file_unique_id` (not persisted), `duration`, optional `mime_type`, optional `file_size`.

Not supported: groups, `video_note`, `audio`, documents, files.

---

## File download

`TelegramNutgramVoiceDownloader` uses Nutgram `getFile($file_id)` + `downloadFile($file, $path)`. Domain code does not build `https://api.telegram.org/file/bot<token>/...` and does not log token or download URLs.

Official cloud Bot API download limit: **20 MB**.

---

## Application limits

Aligned with current Web / Gemini STT (not the 20 MB API ceiling):

| Bound | Actual | Config |
| --- | --- | --- |
| Duration | **30 seconds** | `voice.telegram_voice.max_inbound_seconds` |
| Size | **2_000_000 bytes** | `voice.telegram_voice.max_inbound_bytes` |

Preflight uses Telegram `duration` and `file_size` when present. After download, actual byte length is checked. Audio is never truncated. Oversize / too long → short text, no STT.

---

## MIME

1. Telegram `mime_type` if canonical and in the STT allowlist  
2. `finfo` on the downloaded file  
3. Fallback `audio/ogg` (typical Telegram voice note)

Aliases: `audio/opus`, `application/ogg` → `audio/ogg`. Gemini provider already maps `audio/ogg`. **ffmpeg was not added.**

---

## Temp storage

Inbound and outbound Telegram audio use private `voice-outbound/` (queue worker runs as `deploy`). Web Voice chunks still use `voice-temp/` (php-fpm `www-data`, 0700). Unpredictable filename. Deleted after STT (success or failure). Stale cleanup: `jarvis:voice:cleanup-temp`. Not StoredFile / MessageAttachment / public disk. Raw audio is not archived.

---

## Idempotency

Existing `messages` unique key `(channel, conversation_id, channel_message_id)`. Lookup **before** download/STT.

- Duplicate with assistant reply → silent skip (no second STT/turn/reply)  
- Duplicate user row without assistant → resume turn from stored transcript (no second STT)  
- `ProcessTelegramUpdate`: queue `telegram`, `tries=2`, `timeout=75`  
- `SpeechToTextManager` / Gemini: 2 attempts for connection or 5xx only  

A retry **before** persist can STT again. After persist, it cannot.

Webhook ACK is unchanged (dispatch job, HTTP returns immediately).

---

## STT reuse

`SpeechToTextManager` → current configured `SpeechToTextProvider` → `GeminiSpeechToTextProvider`. Same encrypted Gemini credential / Admin Voice settings as Web Voice. Model ID **not changed**. Not `AiChatGateway`.

Transcript: trim only. Empty → `VOICE_EMPTY` text, no AI turn. Persisted body = transcript. Metadata: `modality=voice`, `source=telegram`, `source_mime`, `duration_seconds`. `file_id` is not stored.

Inbound modality `voice` is passed explicitly into `TelegramReplyDeliveryService`. `auto` is now voice-in → voice-out, text-in → text-out.

---

## Failures (always text, never a voice error bubble)

getFile/download, too large, too long, unsupported MIME, STT unconfigured, timeout, rate limit, provider error, empty transcript, temp failure. No ConversationTurn on those paths. Worker is not crashed.

---

## Also in this commit (needed for live replies)

HTTP multipart `sendVoice` via `TelegramBotManager` (Nutgram `InputFile` + persistent keyboard on voice previously failed). Outbound TTS temp on `voice-outbound/`. Owner already confirmed Telegram Voice Replies (MANUAL PASS).

---

## Non-regression

- Groups: `[voice]` placeholder, no STT (unit-tested mapper)  
- Reminders: untouched  
- Web Voice / `VoiceRuntimeService` / `voice_sessions`: not used by this path  
- Gemini STT provider internals: unchanged aside from `audio/opus` alias in `VoiceAudioMime`  
- Disabled user: no STT/AI  

---

## Tests / static

- `vendor/bin/phpunit tests/Unit/Telegram/TelegramVoiceInputTest.php tests/Unit/Telegram/TelegramVoiceRepliesTest.php` — 22 passed, no production DB  
- `php -l` on touched PHP  
- Pint on dirty PHP  
- `php artisan migrate:status` — no new migration  

**NO LIVE TELEGRAM. NO LIVE GEMINI. NO LIVE AI. NO LIVE ELEVENLABS.**

---

## Owner manual checklist

Set mode **auto**. Same conversation as usual («Основной»).

1. Voice note: «Назови число сорок два.» → one turn, **voice** bubble.  
2. Text: «Назови число сорок три.» → **text** reply.  
3. Set mode **text**. Voice note → **text** reply.  
4. Set mode **voice**. Voice note → **voice** reply.  
5. Very quiet/empty voice note → text «Не удалось разобрать голосовое сообщение. Попробуйте ещё раз.» — no invented AI answer.

Voice notes longer than 30 seconds or larger than 2 MB should get a short text limit message.

---

## Known limitations

- 30 s / 2 MB (same as Web STT), not Telegram’s 20 MB  
- Groups / video notes / audio files out of scope  
- Job retry before persist can STT twice  
- Gemini empty-audio currently surfaces as STT failure in the provider; adapter also treats empty transcript as `VOICE_EMPTY`  
- `voice-temp/` remains www-data 0700 (Web Voice); Telegram uses `voice-outbound/`  

---

## Subsequent voice changes (2026-09-05)

These changes landed after the Telegram Voice Input commit described above:

### Web Voice: «Рация» only

- Removed hands-free «Диалог», automatic VAD capture, and its mode selector.
- The only capture flow is push-to-talk: hold to record, release to submit.
- Pressing push-to-talk while Jarvis is speaking/thinking interrupts first.
- Existing Voice Runtime, Gemini STT, `ConversationTurnService`, persisted messages, and ElevenLabs TTS remain unchanged.
- Commit: `2b3a972` (`fix: keep web voice in push-to-talk mode`).

### Orb visibility

- Increased WebGL glow/opacity intensity by about 12% on desktop and 50% below 768px.
- Applied matching responsive brightness/saturation to the CSS fallback Orb.
- Commit: `79ae9af` (`fix: brighten voice orb on mobile`).

### Per-user assistant voice

- Added six curated ElevenLabs choices: Jessica, Sarah, Lily; Eric, George, Chris.
- Voice selection moved from global Admin Voice settings to each user's Workspace settings.
- Migration `2026_09_05_202207_add_voice_id_to_users_table.php` adds nullable `users.voice_id`.
- `VoiceSettingsService` resolves user preference → configured instance fallback → catalog default.
- Both Web Voice (`VoiceRuntimeService`) and Telegram voice delivery (`TelegramReplyDeliveryService`) pass the resolved user's Voice ID to the existing TTS abstraction.
- Validation only accepts catalog IDs. A user's selection does not affect another user.
- Commits: `e25bf3a` (`feat: add curated assistant voice selection`) and `ee7d4f0` (`fix: make assistant voice a user preference`).

The original “No schema migration / no production DB writes” statement at the top applies only to the Telegram Voice Input commit. The later per-user voice change intentionally includes the migration above.

### Verification

- Per-user catalog/update/isolation/validation feature tests passed.
- Telegram TTS propagation test for distinct user Voice IDs passed.
- PHP formatting and the production frontend build passed.
