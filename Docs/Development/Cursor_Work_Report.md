# Telegram TTS Speed

## Starting HEAD

`c6d21ae3eaf7297eeed736e2e77d871746c3802d` (`fix: distinguish gmail alerts from digests`).

Branch `main`. No dependency changes.

## Current Telegram TTS path

Telegram voice replies: `TelegramReplyDeliveryService` → `SpeechSynthesizer` / `TextToSpeechManager` → `ElevenLabsTextToSpeechProvider` → ElevenLabs HTTP TTS → temp MP3 → `sendVoice`.

Web Рация: `VoiceRuntimeService` → the same synthesizer **without** TTS options.

Диалог Beta: `ElevenLabsRealtimeSessionService` / `ElevenLabsRealtimeClient` signed websocket; no HTTP TTS payload.

## Settings architecture

Additive `voice_settings.telegram_tts_speed` (nullable decimal 3,2).

`VoiceSettingsService::telegramTtsSpeed()`: DB admin value → `config('voice.telegram_voice.tts_speed')` → **1.15**. Clamp **0.70…1.20**. Invalid / NaN → 1.15.

Admin payload: `telegram_tts_speed`, `_min` 0.70, `_max` 1.20, `_step` 0.05, `_default` 1.15.

Not exposed in ordinary user Workspace voice picker.

## ElevenLabs request change

`TextToSpeechOptions` optional third argument on `synthesize()`. Provider stays transport-focused. When speed is present it is merged into `voice_settings` (does not drop stability / similarity_boost / style / use_speaker_boost if a caller supplies them). Web two-arg synthesize still sends only `text` + `model_id`.

## Telegram-only scoping

Only `TelegramReplyDeliveryService` passes `TextToSpeechOptions::withSpeed($this->ttsSpeed->telegramTtsSpeed())`.

## Admin UI

Settings → Integrations → Voice/Speech: slider + numeric value, labels 0.70 / 1.00 / 1.20. Saves with the existing Voice settings POST.

## Validation

Controller: required numeric `between:0.7,1.2`. Service clamp. Provider clamp; invalid speed is not sent.

## Fallback voice behavior

Preferred-voice failure still retries the instance fallback voice with the **same** options/speed.

## Files changed

New: `ResolvesTelegramTtsSpeed`, `TextToSpeechOptions`, migration, tests, `RestoresVoiceSettings`.

Updated: Voice settings service/model/config/admin UI/controller, TTS contracts/manager/providers, Telegram reply delivery, docs.

## Migration

`2026_09_08_125830_add_telegram_tts_speed_to_voice_settings_table` — additive nullable column. No rollback run.

## Tests authored but NOT executed

- default 1.15
- config fallback / min-max / invalid strings
- saved setting + admin persist / range reject / non-admin 403
- Telegram synthesis passes speed
- fallback voice keeps speed
- Web TTS two-arg path has no `voice_settings`
- realtime / Рация sources do not reference Telegram speed

PHPUnit / `php artisan test` / Pest were not run.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty --format agent`, `composer validate`, `npm run build`, `git diff --check`, `php artisan migrate --force` for the new migration only.

## Owner validation

1. Admin → Voice settings: Telegram TTS speed = 1.15.
2. Telegram voice reply vs previous pace.
3. Set 1.20 — faster. Set 1.00 — normal ElevenLabs pace.
4. Web Voice unchanged.

## Production safety

No live ElevenLabs calls from Cursor. No Telegram voice messages sent. No production user rows edited. Unrelated dirty workspace files were not committed.
