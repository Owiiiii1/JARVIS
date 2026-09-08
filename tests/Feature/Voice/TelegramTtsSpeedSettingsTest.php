<?php

namespace Tests\Feature\Voice;

use App\Enums\UserRole;
use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;
use App\Models\User;
use App\Models\VoiceSetting;
use App\Services\Voice\VoiceSettingsService;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\RestoresVoiceSettings;
use Tests\TestCase;

class TelegramTtsSpeedSettingsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresVoiceSettings;

    public function test_saved_telegram_tts_speed_is_used(): void
    {
        $this->snapshotVoiceSettings();

        try {
            app(VoiceSettingsService::class)->update([
                'telegram_tts_speed' => 1.20,
            ]);

            $this->assertSame(1.2, app(VoiceSettingsService::class)->telegramTtsSpeed());
            $this->assertSame(1.2, (float) VoiceSetting::query()->value('telegram_tts_speed'));
        } finally {
            $this->restoreVoiceSettings();
        }
    }

    public function test_admin_can_persist_telegram_tts_speed(): void
    {
        $owner = null;
        $this->snapshotVoiceSettings();

        try {
            $owner = $this->temporaryOwner();
            $record = app(VoiceSettingsService::class)->ensureRecord();

            $this->actingAs($owner)->post(route('settings.voice.update'), [
                'stt_provider' => $record->stt_provider instanceof VoiceSttProvider
                    ? $record->stt_provider->value
                    : (string) $record->stt_provider,
                'tts_provider' => $record->tts_provider instanceof VoiceTtsProvider
                    ? $record->tts_provider->value
                    : (string) $record->tts_provider,
                'spoken_style_enabled' => $record->spoken_style_enabled ? '1' : '0',
                'stt_model' => (string) ($record->stt_model ?: ''),
                'telegram_tts_speed' => 1.20,
            ])->assertRedirect();

            $this->assertSame(1.2, app(VoiceSettingsService::class)->telegramTtsSpeed());
        } finally {
            $this->restoreVoiceSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_admin_rejects_telegram_tts_speed_outside_range(): void
    {
        $owner = null;
        $this->snapshotVoiceSettings();

        try {
            $owner = $this->temporaryOwner();
            $before = app(VoiceSettingsService::class)->telegramTtsSpeed();
            $record = app(VoiceSettingsService::class)->ensureRecord();

            $this->actingAs($owner)->from(route('settings.index', ['tab' => 'integrations', 'section' => 'voice']))
                ->post(route('settings.voice.update'), [
                    'stt_provider' => $record->stt_provider instanceof VoiceSttProvider
                        ? $record->stt_provider->value
                        : (string) $record->stt_provider,
                    'tts_provider' => $record->tts_provider instanceof VoiceTtsProvider
                        ? $record->tts_provider->value
                        : (string) $record->tts_provider,
                    'spoken_style_enabled' => $record->spoken_style_enabled ? '1' : '0',
                    'stt_model' => (string) ($record->stt_model ?: ''),
                    'telegram_tts_speed' => 1.50,
                ])
                ->assertRedirect(route('settings.index', ['tab' => 'integrations', 'section' => 'voice']))
                ->assertSessionHasErrors('telegram_tts_speed');

            $this->assertSame($before, app(VoiceSettingsService::class)->telegramTtsSpeed());
        } finally {
            $this->restoreVoiceSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_regular_user_cannot_update_telegram_tts_speed(): void
    {
        $user = null;
        $this->snapshotVoiceSettings();

        try {
            $user = $this->createTemporaryUser();
            $before = app(VoiceSettingsService::class)->telegramTtsSpeed();

            $this->actingAs($user)->post(route('settings.voice.update'), [
                'stt_provider' => 'gemini',
                'tts_provider' => 'elevenlabs',
                'spoken_style_enabled' => '1',
                'telegram_tts_speed' => 1.20,
            ])->assertForbidden();

            $this->assertSame($before, app(VoiceSettingsService::class)->telegramTtsSpeed());
        } finally {
            $this->restoreVoiceSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_admin_payload_exposes_telegram_tts_speed_bounds(): void
    {
        $payload = app(VoiceSettingsService::class)->adminPayload();

        $this->assertArrayHasKey('telegram_tts_speed', $payload);
        $this->assertSame(0.7, $payload['telegram_tts_speed_min']);
        $this->assertSame(1.2, $payload['telegram_tts_speed_max']);
        $this->assertSame(0.05, $payload['telegram_tts_speed_step']);
        $this->assertSame(1.15, $payload['telegram_tts_speed_default']);
        $this->assertGreaterThanOrEqual(0.7, $payload['telegram_tts_speed']);
        $this->assertLessThanOrEqual(1.2, $payload['telegram_tts_speed']);
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
