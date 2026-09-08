<?php

namespace Tests\Unit\Voice;

use App\Services\Ai\GeminiCredentialResolver;
use App\Services\Voice\VoiceSettingsService;

class VoiceSettingsTelegramTtsSpeedTest extends VoiceProviderTestCase
{
    public function test_default_telegram_tts_speed_is_1_15(): void
    {
        config([
            'voice.telegram_voice.tts_speed' => null,
            'voice.telegram_voice.tts_speed_min' => 0.70,
            'voice.telegram_voice.tts_speed_max' => 1.20,
        ]);

        $this->assertSame(1.15, $this->service()->telegramTtsSpeed());
    }

    public function test_config_fallback_is_used_when_database_is_unavailable(): void
    {
        config([
            'voice.telegram_voice.tts_speed' => 1.05,
            'voice.telegram_voice.tts_speed_min' => 0.70,
            'voice.telegram_voice.tts_speed_max' => 1.20,
        ]);

        $this->assertSame(1.05, $this->service()->telegramTtsSpeed());
    }

    public function test_values_below_minimum_are_clamped_to_0_70(): void
    {
        config([
            'voice.telegram_voice.tts_speed' => 0.40,
            'voice.telegram_voice.tts_speed_min' => 0.70,
            'voice.telegram_voice.tts_speed_max' => 1.20,
        ]);

        $this->assertSame(0.7, $this->service()->telegramTtsSpeed());
    }

    public function test_values_above_maximum_are_clamped_to_1_20(): void
    {
        config([
            'voice.telegram_voice.tts_speed' => 1.90,
            'voice.telegram_voice.tts_speed_min' => 0.70,
            'voice.telegram_voice.tts_speed_max' => 1.20,
        ]);

        $this->assertSame(1.2, $this->service()->telegramTtsSpeed());
    }

    public function test_invalid_strings_fall_back_to_default(): void
    {
        config([
            'voice.telegram_voice.tts_speed' => 'fast',
            'voice.telegram_voice.tts_speed_min' => 0.70,
            'voice.telegram_voice.tts_speed_max' => 1.20,
        ]);

        $this->assertSame(1.15, $this->service()->telegramTtsSpeed());
    }

    private function service(): VoiceSettingsService
    {
        return new VoiceSettingsService(new GeminiCredentialResolver);
    }
}
