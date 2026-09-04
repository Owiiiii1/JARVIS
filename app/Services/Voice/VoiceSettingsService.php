<?php

namespace App\Services\Voice;

use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;
use App\Models\AiProviderSetting;
use App\Models\VoiceSetting;
use App\Services\Voice\DTO\VoiceEffectiveSettings;
use Illuminate\Support\Facades\Schema;

final class VoiceSettingsService
{
    public function record(): ?VoiceSetting
    {
        if (! $this->tableReady()) {
            return null;
        }

        return VoiceSetting::query()->first();
    }

    public function ensureRecord(): VoiceSetting
    {
        $record = $this->record();

        if ($record !== null) {
            return $record;
        }

        $effective = $this->fromConfig();

        return VoiceSetting::query()->create([
            'stt_provider' => $effective->sttProvider,
            'tts_provider' => $effective->ttsProvider,
            'spoken_style_enabled' => $effective->spokenStyleEnabled,
            'elevenlabs_api_key' => null,
            'elevenlabs_voice_id' => $effective->elevenLabsVoiceId,
        ]);
    }

    public function effective(): VoiceEffectiveSettings
    {
        $record = $this->record();

        if ($record === null) {
            return $this->fromConfig();
        }

        $voiceId = trim((string) ($record->elevenlabs_voice_id ?: ''));

        return new VoiceEffectiveSettings(
            sttProvider: $record->stt_provider instanceof VoiceSttProvider
                ? $record->stt_provider
                : VoiceSttProvider::normalize($record->stt_provider),
            ttsProvider: $record->tts_provider instanceof VoiceTtsProvider
                ? $record->tts_provider
                : VoiceTtsProvider::normalize($record->tts_provider),
            spokenStyleEnabled: (bool) $record->spoken_style_enabled,
            spokenStyleHint: $this->spokenHint(),
            elevenLabsVoiceId: $voiceId !== '' ? $voiceId : $this->fromConfig()->elevenLabsVoiceId,
        );
    }

    public function spokenStyleEnabled(): bool
    {
        return $this->effective()->spokenStyleEnabled;
    }

    public function spokenStyleHint(): string
    {
        return $this->effective()->spokenStyleHint;
    }

    public function elevenLabsApiKey(): string
    {
        $record = $this->record();
        $stored = $record !== null ? trim((string) $record->elevenlabs_api_key) : '';

        if ($stored !== '') {
            return $stored;
        }

        return trim((string) config('voice.elevenlabs.api_key', ''));
    }

    public function elevenLabsKeySource(): ?string
    {
        $record = $this->record();
        $stored = $record !== null ? trim((string) $record->elevenlabs_api_key) : '';

        if ($stored !== '') {
            return 'admin';
        }

        if (trim((string) config('voice.elevenlabs.api_key', '')) !== '') {
            return 'env';
        }

        return null;
    }

    public function openaiConfigured(): bool
    {
        $credential = AiProviderSetting::query()->where('provider', 'openai')->first();

        return $credential !== null && $credential->is_connected && filled($credential->api_key);
    }

    public function openaiApiKey(): string
    {
        $credential = AiProviderSetting::query()->where('provider', 'openai')->first();

        if ($credential === null || ! $credential->is_connected) {
            return '';
        }

        return trim((string) $credential->api_key);
    }

    public function sttConfigured(): bool
    {
        $provider = $this->effective()->sttProvider;

        return match ($provider) {
            VoiceSttProvider::OpenAi => $this->openaiConfigured(),
            VoiceSttProvider::None => false,
        };
    }

    public function ttsConfigured(): bool
    {
        $provider = $this->effective()->ttsProvider;

        return match ($provider) {
            VoiceTtsProvider::ElevenLabs => $this->elevenLabsApiKey() !== '',
            VoiceTtsProvider::None => false,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): VoiceSetting
    {
        $record = $this->ensureRecord();
        $record->fill($attributes);
        $record->save();

        return $record;
    }

    public function setElevenLabsApiKey(string $key): void
    {
        $record = $this->ensureRecord();
        $record->elevenlabs_api_key = trim($key);
        $record->save();
    }

    public function clearElevenLabsApiKey(): void
    {
        $record = $this->ensureRecord();
        $record->elevenlabs_api_key = null;
        $record->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayload(): array
    {
        $effective = $this->effective();
        $sttConfigured = $this->sttConfigured();
        $ttsConfigured = $this->ttsConfigured();
        $elevenConfigured = $this->elevenLabsApiKey() !== '';

        $status = 'not_configured';
        $statusLabel = 'Not configured';

        if ($sttConfigured || $ttsConfigured) {
            $status = 'partial';
            $statusLabel = 'Partial';
        }

        if ($sttConfigured && $ttsConfigured) {
            $status = 'ready';
            $statusLabel = 'Ready';
        }

        return [
            'status' => $status,
            'status_label' => $statusLabel,
            'stt_provider' => $effective->sttProvider->value,
            'stt_provider_label' => $effective->sttProvider->label(),
            'stt_configured' => $sttConfigured,
            'tts_provider' => $effective->ttsProvider->value,
            'tts_provider_label' => $effective->ttsProvider->label(),
            'tts_configured' => $ttsConfigured,
            'spoken_style_enabled' => $effective->spokenStyleEnabled,
            'openai_configured' => $this->openaiConfigured(),
            'elevenlabs_configured' => $elevenConfigured,
            'elevenlabs_key_source' => $this->elevenLabsKeySource(),
            'elevenlabs_voice_id' => $effective->elevenLabsVoiceId,
            'limits' => [
                'max_audio_chunk_bytes' => (int) config('voice.max_audio_chunk_bytes'),
                'max_utterance_seconds' => (int) config('voice.max_utterance_seconds'),
                'max_sessions_per_user' => (int) config('voice.max_sessions_per_user'),
                'inactivity_timeout_seconds' => (int) config('voice.inactivity_timeout_seconds'),
                'session_ttl_seconds' => (int) config('voice.session_ttl_seconds'),
                'max_text_for_tts' => (int) config('voice.max_text_for_tts'),
                'stt_timeout_seconds' => (int) config('voice.stt_timeout_seconds'),
                'tts_timeout_seconds' => (int) config('voice.tts_timeout_seconds'),
            ],
        ];
    }

    private function fromConfig(): VoiceEffectiveSettings
    {
        $voiceId = trim((string) config('voice.elevenlabs.voice_id', ''));

        return new VoiceEffectiveSettings(
            sttProvider: VoiceSttProvider::normalize(config('voice.stt_provider')),
            ttsProvider: VoiceTtsProvider::normalize(config('voice.tts_provider')),
            spokenStyleEnabled: (bool) config('voice.spoken_style.enabled', true),
            spokenStyleHint: $this->spokenHint(),
            elevenLabsVoiceId: $voiceId !== '' ? $voiceId : null,
        );
    }

    private function spokenHint(): string
    {
        return trim((string) config(
            'voice.spoken_style.hint',
            'Response will be spoken aloud; prefer concise natural spoken sentences unless detail is requested.',
        ));
    }

    private function tableReady(): bool
    {
        return Schema::hasTable('voice_settings');
    }
}
