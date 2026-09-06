<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Reminders\PushSubscriptionService;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\VapidConfig;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisPushSubscriptionController extends Controller
{
    public function __construct(
        private readonly PushSubscriptionService $subscriptions,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        return response()->json([
            'configured' => VapidConfig::isConfigured(),
            'vapid_public_key' => VapidConfig::publicKey(),
            'active' => $this->subscriptions->hasActive($user),
            'count' => $this->subscriptions->activeFor($user)->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:512'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'user_agent' => ['nullable', 'string', 'max:512'],
        ]);

        try {
            $this->subscriptions->subscribe(
                $user,
                $validated['endpoint'],
                $validated['keys']['p256dh'],
                $validated['keys']['auth'],
                $validated['user_agent'] ?? $request->userAgent(),
            );
        } catch (ReminderException $exception) {
            $status = $exception->error === 'not_found' ? 404 : 422;

            return response()->json([
                'error' => $exception->error,
                'message' => $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'active' => true,
            'vapid_public_key' => VapidConfig::publicKey(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:512'],
        ]);

        try {
            $this->subscriptions->unsubscribe($user, $validated['endpoint']);
        } catch (ReminderException $exception) {
            return response()->json([
                'error' => $exception->error,
                'message' => $exception->getMessage(),
            ], $exception->error === 'not_found' ? 404 : 422);
        }

        return response()->json([
            'ok' => true,
            'active' => false,
        ]);
    }

    private function assertReminders($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::REMINDERS)) {
            abort(403);
        }
    }
}
