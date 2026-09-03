<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            
            'owlAdmin' => fn () => [
                ...config('owl-admin.branding', [
                    'brand_name' => config('owl-admin.brand_name', config('owl-admin.name', 'Service Admin')),
                    'logo_path' => config('owl-admin.logo_path', '/images/company-logo.svg'),
                ]),
                'ai' => (function (): array {
                    $fallback = [
                        'connected' => false,
                        'provider' => null,
                        'provider_label' => null,
                        'model' => null,
                        'status_label' => 'AI: not connected',
                    ];

                    try {
                        if (! class_exists(\App\Models\AiProviderSetting::class)) {
                            return $fallback;
                        }

                        if (! \Illuminate\Support\Facades\Schema::hasTable('ai_provider_settings')) {
                            return $fallback;
                        }

                        $active = \App\Models\AiProviderSetting::query()
                            ->where('is_active', true)
                            ->where('is_connected', true)
                            ->first();

                        if ($active === null) {
                            return $fallback;
                        }

                        $providerLabel = $active->label ?: ucfirst((string) $active->provider);

                        return [
                            'connected' => true,
                            'provider' => $active->provider,
                            'provider_label' => $providerLabel,
                            'model' => $active->active_model,
                            'status_label' => sprintf(
                                'AI: connected — %s / %s',
                                $providerLabel,
                                $active->active_model ?? 'unknown'
                            ),
                        ];
                    } catch (\Throwable) {
                        return $fallback;
                    }
                })(),
                'telegram' => (function (): array {
                    $fallback = [
                        'configured' => false,
                        'connected' => false,
                        'webhook_set' => false,
                        'bot_username' => null,
                        'status' => 'not_connected',
                        'status_label' => 'Bot: not connected',
                    ];

                    try {
                        if (! class_exists(\App\Models\TelegramBotSetting::class)) {
                            return $fallback;
                        }

                        if (! \Illuminate\Support\Facades\Schema::hasTable('telegram_bot_settings')) {
                            return $fallback;
                        }

                        $setting = \App\Models\TelegramBotSetting::query()->first();
                        if ($setting === null || ! filled($setting->bot_token)) {
                            return $fallback;
                        }

                        $username = $setting->bot_username;
                        $connected = (bool) $setting->is_connected;
                        $webhookSet = (bool) $setting->is_webhook_set;

                        if ($connected && $webhookSet && filled($username)) {
                            return [
                                'configured' => true,
                                'connected' => true,
                                'webhook_set' => true,
                                'bot_username' => $username,
                                'status' => 'connected',
                                'status_label' => sprintf('Bot: connected — @%s', $username),
                            ];
                        }

                        return [
                            'configured' => true,
                            'connected' => $connected,
                            'webhook_set' => $webhookSet,
                            'bot_username' => $username,
                            'status' => 'incomplete',
                            'status_label' => 'Bot: incomplete',
                        ];
                    } catch (\Throwable) {
                        return $fallback;
                    }
                })(),
            ],
        ];
    }
}
