<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBotManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class TelegramSettingsController extends Controller
{
    public function __construct(
        private readonly TelegramBotManager $telegram
    ) {}

    public function saveToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_token' => ['required', 'string', 'max:255'],
        ]);

        $setting = $this->telegram->setting();
        $setting->fill([
            'bot_token' => trim($validated['bot_token']),
            'is_connected' => false,
            'is_webhook_set' => false,
            'bot_username' => null,
            'last_error' => null,
            'last_checked_at' => null,
            'last_webhook_set_at' => null,
        ])->save();

        return back()->with('success', 'Telegram bot token saved.');
    }

    public function check(): RedirectResponse
    {
        $setting = $this->telegram->setting();
        if (! filled($setting->bot_token)) {
            return back()->withErrors(['telegram' => 'Save a bot token before checking.']);
        }

        try {
            $me = $this->telegram->getMe((string) $setting->bot_token);
            $setting->fill([
                'bot_username' => $me['username'] ?? null,
                'is_connected' => true,
                'last_checked_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            return back()->with('success', 'Telegram bot connected.');
        } catch (Throwable $e) {
            $setting->fill([
                'is_connected' => false,
                'last_checked_at' => Carbon::now(),
                'last_error' => $e->getMessage(),
            ])->save();

            return back()->withErrors(['telegram' => $e->getMessage()]);
        }
    }

    public function setWebhook(): RedirectResponse
    {
        $setting = $this->telegram->setting();
        if (! filled($setting->bot_token)) {
            return back()->withErrors(['telegram' => 'Save a bot token before setting webhook.']);
        }

        try {
            $secret = $this->telegram->ensureWebhookSecret($setting);
            $url = $this->telegram->webhookUrl();
            $this->telegram->setWebhook((string) $setting->bot_token, $url, $secret);

            $setting->fill([
                'webhook_url' => $url,
                'is_webhook_set' => true,
                'last_webhook_set_at' => Carbon::now(),
                'last_error' => null,
            ])->save();

            return back()->with('success', 'Telegram webhook set.');
        } catch (Throwable $e) {
            $setting->fill([
                'is_webhook_set' => false,
                'last_error' => $e->getMessage(),
            ])->save();

            return back()->withErrors(['telegram' => $e->getMessage()]);
        }
    }

    public function removeWebhook(): RedirectResponse
    {
        $setting = $this->telegram->setting();
        if (! filled($setting->bot_token)) {
            return back()->withErrors(['telegram' => 'Save a bot token before removing webhook.']);
        }

        try {
            $this->telegram->deleteWebhook((string) $setting->bot_token);
            // Keep last known webhook_url for reference; clear set flag.
            $setting->fill([
                'is_webhook_set' => false,
                'last_error' => null,
            ])->save();

            return back()->with('success', 'Telegram webhook removed.');
        } catch (Throwable $e) {
            $setting->fill([
                'last_error' => $e->getMessage(),
            ])->save();

            return back()->withErrors(['telegram' => $e->getMessage()]);
        }
    }
}
