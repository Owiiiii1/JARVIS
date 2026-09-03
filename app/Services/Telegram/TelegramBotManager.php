<?php

namespace App\Services\Telegram;

use App\Models\TelegramBotSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class TelegramBotManager
{
    public function setting(): TelegramBotSetting
    {
        /** @var TelegramBotSetting $setting */
        $setting = TelegramBotSetting::query()->firstOrCreate([]);

        return $setting;
    }

    /**
     * @return array{id: int|null, username: string|null, first_name: string|null}
     */
    public function getMe(string $token): array
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $me = $bot->getMe();

                return [
                    'id' => $me->id ?? null,
                    'username' => $me->username ?? null,
                    'first_name' => $me->first_name ?? null,
                ];
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram getMe failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->get($this->apiUrl($token, 'getMe'));
        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram getMe failed: '.$response->body());
        }

        $result = $response->json('result') ?? [];

        return [
            'id' => $result['id'] ?? null,
            'username' => $result['username'] ?? null,
            'first_name' => $result['first_name'] ?? null,
        ];
    }

    public function setWebhook(string $token, string $url, string $secret): void
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $bot->setWebhook($url, secret_token: $secret, drop_pending_updates: false);

                return;
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram setWebhook failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->post($this->apiUrl($token, 'setWebhook'), [
            'url' => $url,
            'secret_token' => $secret,
            'drop_pending_updates' => false,
        ]);

        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram setWebhook failed: '.$response->body());
        }
    }

    public function deleteWebhook(string $token): void
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $bot->deleteWebhook();

                return;
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram deleteWebhook failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->post($this->apiUrl($token, 'deleteWebhook'));
        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram deleteWebhook failed: '.$response->body());
        }
    }

    public function ensureWebhookSecret(TelegramBotSetting $setting): string
    {
        if (filled($setting->webhook_secret)) {
            return (string) $setting->webhook_secret;
        }

        $secret = Str::random(32);
        $setting->forceFill(['webhook_secret' => $secret])->save();

        return $secret;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/telegram/webhook';
    }

    private function apiUrl(string $token, string $method): string
    {
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }
}
