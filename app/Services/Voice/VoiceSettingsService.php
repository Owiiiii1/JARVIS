<?php

namespace App\Services\Voice;

use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;
use App\Models\AiProviderSetting;
use App\Models\User;
use App\Models\VoiceSetting;
use App\Services\Ai\GeminiCredentialResolver;
use App\Services\Voice\Contracts\ResolvesUserVoice;
use App\Services\Voice\DTO\VoiceEffectiveSettings;
use Illuminate\Support\Facades\Schema;

final class VoiceSettingsService implements ResolvesUserVoice
{
    public function __construct(
        private readonly GeminiCredentialResolver $geminiCredentials,
    ) {}

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

        $attributes = [
            'stt_provider' => $effective->sttProvider,
            'tts_provider' => $effective->ttsProvider,
            'spoken_style_enabled' => $effective->spokenStyleEnabled,
            'elevenlabs_api_key' => null,
            'elevenlabs_voice_id' => $effective->elevenLabsVoiceId,
        ];

        if ($this->hasSttModelColumn()) {
            $attributes['stt_model'] = $effective->sttModel !== '' ? $effective->sttModel : null;
        }

        return VoiceSetting::query()->create($attributes);
    }

    public function effective(): VoiceEffectiveSettings
    {
        $record = $this->record();

        if ($record === null) {
            return $this->fromConfig();
        }

        $voiceId = trim((string) ($record->elevenlabs_voice_id ?: ''));

        $provider = $record->stt_provider instanceof VoiceSttProvider
            ? $record->stt_provider
            : VoiceSttProvider::normalize($record->stt_provider);

        return new VoiceEffectiveSettings(
            sttProvider: $provider,
            ttsProvider: $record->tts_provider instanceof VoiceTtsProvider
                ? $record->tts_provider
                : VoiceTtsProvider::normalize($record->tts_provider),
            spokenStyleEnabled: (bool) $record->spoken_style_enabled,
            spokenStyleHint: $this->spokenHint(),
            elevenLabsVoiceId: $voiceId !== '' ? $voiceId : $this->fromConfig()->elevenLabsVoiceId,
            sttModel: $this->sttModelFromRecord($record, $provider),
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

    public function geminiConfigured(): bool
    {
        return $this->geminiCredentials->isConfigured();
    }

    public function sttModel(): string
    {
        return $this->effective()->sttModel;
    }

    public function voiceIdFor(User $user): string
    {
        $selected = trim((string) $user->voice_id);

        if (in_array($selected, ElevenLabsVoiceCatalog::ids(), true)) {
            return $selected;
        }

        $default = trim((string) $this->effective()->elevenLabsVoiceId);

        return in_array($default, ElevenLabsVoiceCatalog::ids(), true)
            ? $default
            : ElevenLabsVoiceCatalog::defaultId();
    }

    /**
     * @return array{voice_id: string, voices: list<array{id: string, name: string, gender: 'female'|'male', style: string}>}
     */
    public function userVoicePayload(User $user): array
    {
        return [
            'voice_id' => $this->voiceIdFor($user),
            'voices' => ElevenLabsVoiceCatalog::options(),
        ];
    }

    public function defaultSttModel(?VoiceSttProvider $provider = null): string
    {
        $provider ??= $this->effective()->sttProvider;

        return match ($provider) {
            VoiceSttProvider::Gemini => trim((string) config('voice.gemini_stt.model', 'gemini-3.5-transcribe')),
            VoiceSttProvider::OpenAi => trim((string) config('voice.openai_stt.model', 'whisper-1')),
            VoiceSttProvider::None => '',
        };
    }

    public function sttTimeoutSeconds(): int
    {
        return max(2, (int) config('voice.stt_timeout_seconds', 20));
    }

    public function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('voice.connect_timeout_seconds', 5));
    }

    public function maxAudioChunkBytes(): int
    {
        return max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000));
    }

    public function maxUtteranceSeconds(): int
    {
        return max(1, (int) config('voice.max_utterance_seconds', 30));
    }

    public function geminiSttBaseUrl(): string
    {
        return rtrim((string) config(
            'voice.gemini_stt.base_url',
            'https://generativelanguage.googleapis.com/v1beta',
        ), '/');
    }

    public function geminiSttMaxInlineBytes(): int
    {
        return max(1024, (int) config('voice.gemini_stt.max_inline_bytes', 20_000_000));
    }

    public function sttConfigured(): bool
    {
        $effective = $this->effective();

        return match ($effective->sttProvider) {
            VoiceSttProvider::Gemini => $this->geminiConfigured() && $effective->sttModel !== '',
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
        if (array_key_exists('stt_model', $attributes) && ! $this->hasSttModelColumn()) {
            unset($attributes['stt_model']);
        }

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
            'stt_model' => $effective->sttModel,
            'stt_model_default' => $this->defaultSttModel($effective->sttProvider),
            'gemini_configured' => $this->geminiConfigured(),
            'gemini_credential_source' => 'ai_provider_settings',
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

        $provider = VoiceSttProvider::normalize(config('voice.stt_provider'));

        return new VoiceEffectiveSettings(
            sttProvider: $provider,
            ttsProvider: VoiceTtsProvider::normalize(config('voice.tts_provider')),
            spokenStyleEnabled: (bool) config('voice.spoken_style.enabled', true),
            spokenStyleHint: $this->spokenHint(),
            elevenLabsVoiceId: $voiceId !== '' ? $voiceId : null,
            sttModel: $this->defaultSttModel($provider),
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

    private function hasSttModelColumn(): bool
    {
        return $this->tableReady() && Schema::hasColumn('voice_settings', 'stt_model');
    }

    private function sttModelFromRecord(VoiceSetting $record, VoiceSttProvider $provider): string
    {
        if ($this->hasSttModelColumn()) {
            $stored = trim((string) ($record->stt_model ?? ''));

            if ($stored !== '') {
                return $stored;
            }
        }

        return $this->defaultSttModel($provider);
    }
}
