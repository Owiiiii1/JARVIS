# Cursor work report — Telegram Voice Replies

**Date:** 2026-09-05  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`

---

## Starting HEAD

| Item | Value |
| --- | --- |
| origin/main | `e4f3ea12f9ed8880f2ad5e942fee82effc35dd2a` |
| Message | `docs: add Telegram voice reply roadmap` |

Did not revert M26D, M25U.3, Voice, isolation, or reminders.

Uncommitted local Web Voice experiments were **not** included in this commit.

---

## Telegram handler path

`TelegramWebhookController` → `TelegramWebhookProcessor` → `TelegramUpdateHandler`.

Paired DM: `handlePairedMessage` → `ConversationTurnService::handleUserMessage` → persist assistant text → **`TelegramReplyDeliveryService`**.

Groups: still `TelegramGroupInboundService` only. No voice replies.

Reminders: still `ReminderDeliveryService` → `TelegramBotManager::sendTextMessage`. Unchanged.

Disabled users: existing reject before the turn.

---

## TTS path

`TextToSpeechManager` → configured `TextToSpeechProvider` (ElevenLabs). Adapter does not read API keys.

Format: **MP3** (`audio/mpeg`, `mp3_44100_128`). Telegram `sendVoice` accepts MP3. **ffmpeg was not required and was not installed.**

---

## Preference storage

New additive table `user_channel_preferences`: `user_id`, `channel`, `response_mode` (`text`/`voice`/`auto`), unique `(user_id, channel)`.

**Default: `text`** (missing row = text). Existing users unchanged after deploy.

Tools: `get_telegram_response_mode`, `set_telegram_response_mode` (capability `telegram_dm`, current user only).

`auto`: voice inbound → voice out. Inbound STT is **not** implemented, so auto currently behaves as text for text inbound.

---

## Delivery policy

`TelegramReplyDeliveryService` (handler stays thin).

- Pending tool confirmation → text + confirm keyboard (no TTS)
- `text` / `auto`+text inbound → `sendMessage`
- `voice` → suitability → TTS → Nutgram `sendVoice` via `TelegramNutgramDmOutbound`
- Failures → one `sendMessage` fallback

No `voice_sessions`. No second message row.

---

## sendVoice

Nutgram `sendVoice` + `InputFile::make` (local temp file). Reply markup = existing menu keyboard (same as text). Not `sendDocument`.

---

## Temp files

`voice-temp/telegram/{userId}/{random}.mp3` on the private voice disk. Deleted after send. Stale cleanup: `jarvis:voice:cleanup-temp`.

---

## Spoken normalization

`SpokenTextNormalizer`: strip emphasis, fenced code, turn markdown links into labels, drop raw URLs. No extra LLM. Canonical DB text unchanged.

Long/unsuitable skip: fenced code total > 400 chars; markdown tables ≥ 4 rows; `artifact` fences; spoken length > **2000** characters (`voice.telegram_voice.max_spoken_chars`). Then text, not truncated canonical.

---

## Static checks

- `php -l` on new/changed PHP
- `vendor/bin/phpunit tests/Unit/Telegram/TelegramVoiceRepliesTest.php` — 11 passed (PHPUnit `TestCase`, no production DB, no providers)
- `php artisan migrate --force` — additive `user_channel_preferences` only
- `migrate:status` Ran

**NO** `php artisan test` against production DB.  
**NO LIVE TELEGRAM / ELEVENLABS / AI.**

---

## Owner manual checklist

1. Telegram: «Отвечай мне голосом» → confirms setting.  
2. Short test message → native voice bubble.  
3. Another question → still voice.  
4. «Отвечай текстом» → later replies text.  
5. Optional TTS misconfig → text fallback, answer not lost.

Owner default remains text until changed.

---

## Known limitations

- Telegram Voice Input not implemented
- `auto` equals text until inbound voice exists
- No Web settings UI (chat tools only)
- Groups unchanged
- Status not MANUAL PASS until Owner confirms live `sendVoice`

---

## NO LIVE TELEGRAM

## NO LIVE ELEVENLABS

## NO LIVE AI
