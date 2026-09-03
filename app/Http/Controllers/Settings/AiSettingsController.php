<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiProviderSetting;
use App\Services\Ai\AiProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiProviderManager $providerManager
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('settings.index', ['tab' => 'ai']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providersPayload(): array
    {
        $this->ensureProvidersExist();

        return AiProviderSetting::query()
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'anthropic' THEN 2 WHEN 'gemini' THEN 3 ELSE 99 END")
            ->get()
            ->map(function (AiProviderSetting $setting): array {
                $models = collect($setting->available_models ?? [])
                    ->filter(fn (array $model): bool => ! empty($model['id']))
                    ->values()
                    ->all();

                return [
                    'provider' => $setting->provider,
                    'label' => $setting->label ?: ucfirst($setting->provider),
                    'has_api_key' => filled($setting->api_key),
                    'api_key_masked' => $this->maskKey($setting->api_key),
                    'is_connected' => (bool) $setting->is_connected,
                    'is_active' => (bool) $setting->is_active,
                    'active_model' => $setting->active_model,
                    'available_models' => $models,
                    'last_checked_at' => optional($setting->last_checked_at)?->toIso8601String(),
                    'last_error' => $setting->last_error,
                ];
            })
            ->all();
    }

    public function saveKey(Request $request, string $provider): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'api_key' => ['required', 'string', 'max:4096'],
        ]);

        if ($validated['provider'] !== $provider) {
            abort(422, 'Provider mismatch.');
        }

        $setting = $this->setting($provider);
        $setting->fill([
            'api_key' => trim($validated['api_key']),
            'last_error' => null,
            'is_connected' => false,
            'available_models' => null,
            'last_checked_at' => null,
        ])->save();

        return back()->with('success', 'API key saved.');
    }

    public function check(Request $request, string $provider): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
        ]);

        if ($validated['provider'] !== $provider) {
            abort(422, 'Provider mismatch.');
        }

        $setting = $this->setting($provider);
        if (! filled($setting->api_key)) {
            return back()->withErrors(['ai' => 'Save API key before checking connection.']);
        }

        try {
            $models = $this->providerManager->listModels($provider, (string) $setting->api_key);
            $setting->fill([
                'is_connected' => true,
                'available_models' => $models,
                'last_checked_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            return back()->with('success', 'Connection checked. Models loaded.');
        } catch (Throwable $e) {
            $setting->fill([
                'is_connected' => false,
                'available_models' => null,
                'last_checked_at' => Carbon::now(),
                'last_error' => $e->getMessage(),
            ])->save();

            return back()->withErrors(['ai' => $e->getMessage()]);
        }
    }

    public function activate(Request $request, string $provider): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'model' => ['required', 'string', 'max:255'],
        ]);

        if ($validated['provider'] !== $provider) {
            abort(422, 'Provider mismatch.');
        }

        $setting = $this->setting($provider);
        $available = collect($setting->available_models ?? [])->pluck('id')->filter()->values()->all();
        if (! in_array($validated['model'], $available, true)) {
            return back()->withErrors([
                'ai' => 'Selected model is not available for this provider. Re-check connection.',
            ]);
        }

        AiProviderSetting::query()->update(['is_active' => false]);
        $setting->fill([
            'is_active' => true,
            'is_connected' => true,
            'active_model' => $validated['model'],
            'last_error' => null,
        ])->save();

        return back()->with('success', 'Provider activated.');
    }

    public function deactivate(): RedirectResponse
    {
        AiProviderSetting::query()->update([
            'is_active' => false,
            'active_model' => null,
        ]);

        return back()->with('success', 'AI provider deactivated.');
    }

    private function ensureProvidersExist(): void
    {
        foreach ($this->providerManager->providers() as $provider) {
            AiProviderSetting::query()->firstOrCreate(
                ['provider' => $provider['provider']],
                ['label' => $provider['label']]
            );
        }
    }

    private function setting(string $provider): AiProviderSetting
    {
        $this->ensureProvidersExist();

        /** @var AiProviderSetting $setting */
        $setting = AiProviderSetting::query()->where('provider', $provider)->firstOrFail();

        return $setting;
    }

    private function maskKey(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $plain = trim((string) $value);
        if (strlen($plain) <= 8) {
            return str_repeat('*', strlen($plain));
        }

        return substr($plain, 0, 4).'...'.substr($plain, -4);
    }
}
