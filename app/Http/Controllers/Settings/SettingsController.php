<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TelegramBotSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => optional($user->created_at)->toIso8601String(),
            ])
            ->all();

        $allowedTabs = ['general', 'users', 'ai', 'app', 'telegram'];
        $tab = (string) $request->query('tab', 'general');
        if (! in_array($tab, $allowedTabs, true)) {
            $tab = 'general';
        }

        /** @var AiSettingsController $aiSettings */
        $aiSettings = app(AiSettingsController::class);

        return Inertia::render('Settings/Index', [
            'users' => $users,
            'providers' => $aiSettings->providersPayload(),
            'telegram' => $this->telegramPayload(),
            'tab' => $tab,
        ]);
    }

    public function updateLanguage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:en,ru,uk'],
        ]);

        $request->session()->put('locale', $validated['locale']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramPayload(): array
    {
        $empty = [
            'has_bot_token' => false,
            'bot_token_masked' => null,
            'bot_username' => null,
            'webhook_url' => null,
            'has_webhook_secret' => false,
            'is_webhook_set' => false,
            'is_connected' => false,
            'last_checked_at' => null,
            'last_webhook_set_at' => null,
            'last_error' => null,
        ];

        try {
            if (! class_exists(TelegramBotSetting::class) || ! Schema::hasTable('telegram_bot_settings')) {
                return $empty;
            }

            /** @var TelegramBotSetting|null $setting */
            $setting = TelegramBotSetting::query()->first();
            if ($setting === null) {
                return $empty;
            }

            return [
                'has_bot_token' => filled($setting->bot_token),
                'bot_token_masked' => $this->maskSecret($setting->bot_token),
                'bot_username' => $setting->bot_username,
                'webhook_url' => $setting->webhook_url,
                'has_webhook_secret' => filled($setting->webhook_secret),
                'is_webhook_set' => (bool) $setting->is_webhook_set,
                'is_connected' => (bool) $setting->is_connected,
                'last_checked_at' => optional($setting->last_checked_at)?->toIso8601String(),
                'last_webhook_set_at' => optional($setting->last_webhook_set_at)?->toIso8601String(),
                'last_error' => $setting->last_error,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    private function maskSecret(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $plain = trim((string) $value);
        $length = strlen($plain);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($plain, 0, 4).'...'.substr($plain, -4);
    }
}
