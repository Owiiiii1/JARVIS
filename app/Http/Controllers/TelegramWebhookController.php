<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramBotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            if (! class_exists(TelegramBotSetting::class) || ! Schema::hasTable('telegram_bot_settings')) {
                return response()->json(['ok' => false, 'error' => 'Telegram not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            /** @var TelegramBotSetting|null $setting */
            $setting = TelegramBotSetting::query()->first();
            if ($setting === null || ! filled($setting->bot_token)) {
                return response()->json(['ok' => false, 'error' => 'Telegram bot not configured.'], Response::HTTP_NOT_FOUND);
            }

            $expected = (string) ($setting->webhook_secret ?? '');
            $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

            if ($expected === '' || ! hash_equals($expected, $provided)) {
                return response()->json(['ok' => false, 'error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
            }

            ProcessTelegramUpdate::dispatch($request->getContent());

            return response()->json(['ok' => true]);
        } catch (Throwable $exception) {
            Log::error('Telegram webhook failed', [
                'error_class' => $exception::class,
            ]);

            return response()->json(
                ['ok' => false, 'error' => 'Webhook unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
