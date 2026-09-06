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
            return $this->error($exception);
        }
    }

    public function update(Request $request, int $reminder): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        $validated = $request->validate([
            'text' => ['sometimes', 'string', 'max:2000'],
            'run_at_local' => ['sometimes', 'string', 'max:64'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'recurrence' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        try {
            $this->reminders->updateOwned(
                $user,
                $reminder,
                $validated['text'] ?? null,
                $validated['run_at_local'] ?? null,
                $validated['timezone'] ?? null,
                array_key_exists('recurrence', $validated) ? $validated['recurrence'] : false,
            );

            return $this->panel($user);
        } catch (ReminderException $exception) {
            return $this->error($exception);
        }
    }

    public function snooze(Request $request, int $reminder): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        $validated = $request->validate([
            'preset' => ['required', 'string', 'in:10m,1h,tomorrow,custom'],
            'run_at_local' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $this->reminders->snoozeOwned(
                $user,
                $reminder,
                $validated['preset'],
                $validated['run_at_local'] ?? null,
            );

            return $this->panel($user);
        } catch (ReminderException $exception) {
            return $this->error($exception);
        }
    }

    public function complete(Request $request, int $reminder): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        try {
            $this->reminders->completeOwned($user, $reminder);

            return $this->panel($user);
        } catch (ReminderException $exception) {
            return $this->error($exception);
        }
    }

    public function cancel(Request $request, int $reminder): JsonResponse
    {
        $user = $request->user();
        $this->assertReminders($user);

        try {
            $this->reminders->cancelOwned($user, $reminder);

            return $this->panel($user);
        } catch (ReminderException $exception) {
            return $this->error($exception);
        }
    }

    private function panel($user): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'active_count' => $this->reminders->activeCount($user),
            ...$this->reminders->panelFor($user),
        ]);
    }

    private function error(ReminderException $exception): JsonResponse
    {
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

    private function assertReminders($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::REMINDERS)) {
            abort(403);
        }
    }
}
