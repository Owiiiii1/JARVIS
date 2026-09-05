<?php

namespace Tests\Feature\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\VoiceSetting;
use App\Services\Voice\VoiceSettingsService;
use Tests\TestCase;

class VoiceSettingsControllerTest extends TestCase
{
    public function test_settings_payload_contains_three_female_and_three_male_voices(): void
    {
        $voices = app(VoiceSettingsService::class)->adminPayload()['elevenlabs_voices'];

        $this->assertCount(6, $voices);
        $this->assertSame(3, count(array_filter($voices, fn (array $voice): bool => $voice['gender'] === 'female')));
        $this->assertSame(3, count(array_filter($voices, fn (array $voice): bool => $voice['gender'] === 'male')));
        $this->assertSame(
            ['Jessica', 'Sarah', 'Lily', 'Eric', 'George', 'Chris'],
            array_column($voices, 'name'),
        );
    }

    public function test_owner_can_select_a_curated_elevenlabs_voice(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->firstOrFail();
        $setting = VoiceSetting::query()->firstOrFail();
        $original = [
            'stt_provider' => $setting->getRawOriginal('stt_provider'),
            'tts_provider' => $setting->getRawOriginal('tts_provider'),
            'spoken_style_enabled' => $setting->getRawOriginal('spoken_style_enabled'),
            'elevenlabs_voice_id' => $setting->getRawOriginal('elevenlabs_voice_id'),
            'stt_model' => $setting->getRawOriginal('stt_model'),
        ];

        try {
            $response = $this->actingAs($owner)->post(route('settings.voice.update'), [
                'stt_provider' => $setting->stt_provider->value,
                'tts_provider' => 'elevenlabs',
                'spoken_style_enabled' => (bool) $setting->spoken_style_enabled,
                'elevenlabs_voice_id' => 'cgSgspJ2msm6clMCkdW9',
                'stt_model' => (string) $setting->stt_model,
            ]);

            $response->assertRedirect();
            $this->assertSame('cgSgspJ2msm6clMCkdW9', $setting->fresh()->elevenlabs_voice_id);
        } finally {
            VoiceSetting::query()->whereKey($setting->id)->update($original);
        }
    }

    public function test_owner_cannot_select_an_unlisted_elevenlabs_voice(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->firstOrFail();
        $setting = VoiceSetting::query()->firstOrFail();
        $originalVoiceId = $setting->elevenlabs_voice_id;

        $response = $this->actingAs($owner)->post(route('settings.voice.update'), [
            'stt_provider' => $setting->stt_provider->value,
            'tts_provider' => 'elevenlabs',
            'spoken_style_enabled' => (bool) $setting->spoken_style_enabled,
            'elevenlabs_voice_id' => 'voice-not-in-curated-list',
            'stt_model' => (string) $setting->stt_model,
        ]);

        $response->assertSessionHasErrors('elevenlabs_voice_id');
        $this->assertSame($originalVoiceId, $setting->fresh()->elevenlabs_voice_id);
    }
}
