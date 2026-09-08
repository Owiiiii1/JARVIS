<?php

namespace Tests\Support;

use App\Models\VoiceSetting;

trait RestoresVoiceSettings
{
    /** @var array<string, mixed>|null */
    private ?array $voiceSettingSnapshot = null;

    private bool $voiceSettingExisted = false;

    private function snapshotVoiceSettings(): void
    {
        $record = VoiceSetting::query()->first();
        $this->voiceSettingExisted = $record !== null;
        $this->voiceSettingSnapshot = $record === null ? null : [
            'stt_provider' => $record->stt_provider,
            'tts_provider' => $record->tts_provider,
            'stt_model' => $record->stt_model ?? null,
            'spoken_style_enabled' => $record->spoken_style_enabled,
            'elevenlabs_api_key' => $record->elevenlabs_api_key,
            'elevenlabs_voice_id' => $record->elevenlabs_voice_id,
            'telegram_tts_speed' => $record->telegram_tts_speed,
        ];
    }

    private function restoreVoiceSettings(): void
    {
        $record = VoiceSetting::query()->first();

        if (! $this->voiceSettingExisted) {
            $record?->delete();
            $this->voiceSettingSnapshot = null;

            return;
        }

        if ($record === null) {
            VoiceSetting::query()->create($this->voiceSettingSnapshot ?? []);
        } else {
            $record->fill($this->voiceSettingSnapshot ?? [])->save();
        }

        $this->voiceSettingSnapshot = null;
    }
}
