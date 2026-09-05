<?php

namespace App\Http\Controllers\Settings;

use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;
use App\Http\Controllers\Controller;
use App\Services\Users\UserCapability;
use App\Services\Voice\ElevenLabsVoiceCatalog;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoiceSettingsController extends Controller
{
    public function __construct(
        private readonly VoiceSettingsService $settings,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $request->merge([
            'spoken_style_enabled' => $request->boolean('spoken_style_enabled'),
        ]);

        $validated = $request->validate([
            'stt_provider' => ['required', Rule::enum(VoiceSttProvider::class)],
            'tts_provider' => ['required', Rule::enum(VoiceTtsProvider::class)],
            'spoken_style_enabled' => ['required', 'boolean'],
            'elevenlabs_voice_id' => [
                'required_if:tts_provider,elevenlabs',
                'nullable',
                'string',
                Rule::in(ElevenLabsVoiceCatalog::ids()),
            ],
            'stt_model' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]*$/'],
        ]);

        $provider = VoiceSttProvider::from($validated['stt_provider']);
        $model = trim((string) ($validated['stt_model'] ?? ''));

        if ($model === '') {
            $model = $this->settings->defaultSttModel($provider);
        }

        $this->settings->update([
            'stt_provider' => $provider,
            'tts_provider' => $validated['tts_provider'],
            'spoken_style_enabled' => $validated['spoken_style_enabled'],
            'elevenlabs_voice_id' => filled($validated['elevenlabs_voice_id'] ?? null)
                ? trim((string) $validated['elevenlabs_voice_id'])
                : null,
            'stt_model' => $model !== '' ? $model : null,
        ]);

        return back()->with('success', 'Voice settings saved.');
    }

    public function saveElevenLabsKey(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'elevenlabs_api_key' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $this->settings->setElevenLabsApiKey($validated['elevenlabs_api_key']);

        return back()->with('success', 'ElevenLabs API key saved.');
    }

    public function clearElevenLabsKey(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $this->settings->clearElevenLabsApiKey();

        return back()->with('success', 'ElevenLabs API key removed. Env ELEVENLABS_API_KEY remains a fallback if set.');
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
