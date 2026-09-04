<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisReminderController extends Controller
{
    public function __construct(
        private readonly ReminderService $reminders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        try {
            return response()->json($this->reminders->panelFor($user));
        } catch (ReminderException $exception) {
            return response()->json([
                'error' => $exception->error,
                'message' => $exception->getMessage(),
            ], $exception->error === 'capability_denied' ? 403 : 422);
        }
    }

    public function cancel(Request $request, int $reminder): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        try {
            $this->reminders->cancelOwned($user, $reminder);

            return response()->json([
                'ok' => true,
                'active_count' => $this->reminders->activeCount($user),
                ...$this->reminders->panelFor($user),
            ]);
        } catch (ReminderException $exception) {
            $status = match ($exception->error) {
                'not_found' => 404,
                'capability_denied' => 403,
                default => 422,
            };

            return response()->json([
                'error' => $exception->error,
                'message' => $exception->getMessage(),
            ], $status);
        }
    }

    private function assertReminders($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::REMINDERS)) {
            abort(403);
        }
    }
}
