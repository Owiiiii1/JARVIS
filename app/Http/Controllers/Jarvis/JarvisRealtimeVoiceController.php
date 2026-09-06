<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VoiceSession;
use App\Services\Conversations\MessageHistoryService;
use App\Services\Conversations\PersonalChatSurfaceService;
use App\Services\Users\UserCapability;
use App\Services\Voice\ElevenLabsRealtimeSessionService;
use App\Services\Voice\Exceptions\VoiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisRealtimeVoiceController extends Controller
{
    public function __construct(
        private readonly ElevenLabsRealtimeSessionService $realtime,
        private readonly PersonalChatSurfaceService $chats,
        private readonly MessageHistoryService $history,
    ) {}

    public function store(Request $request, int $conversation): JsonResponse
    {
        $user = $request->user();
        $this->authorizeVoice($user);
        $current = $this->chats->ensureOwned($user, $conversation);

        try {
            $payload = $this->realtime->start($user, $current);
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, VoiceSession $session): JsonResponse
    {
        $this->authorizeVoice($request->user());

        try {
            return response()->json($this->realtime->turnSnapshot($request->user(), $session, $this->history));
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    public function metrics(Request $request, VoiceSession $session): JsonResponse
    {
        $this->authorizeVoice($request->user());
        $validated = $request->validate([
            'session_connect_ms' => ['nullable', 'integer', 'min:0', 'max:120000'],
            'first_audio_ms' => ['nullable', 'integer', 'min:0', 'max:120000'],
            'total_turn_ms' => ['nullable', 'integer', 'min:0', 'max:120000'],
            'external_conversation_id' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $this->realtime->recordClientMetrics($request->user(), $session, $validated);
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, VoiceSession $session): JsonResponse
    {
        $this->authorizeVoice($request->user());

        try {
            $ended = $this->realtime->end($request->user(), $session);
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }

        return response()->json([
            'public_id' => $ended->public_id,
            'conversation_id' => (int) $ended->conversation_id,
            'status' => $ended->status->value,
        ]);
    }

    private function error(VoiceException $exception): JsonResponse
    {
        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
        ], $exception->httpStatus);
    }

    private function authorizeVoice(?User $user): void
    {
        if ($user === null || ! $user->canUseCapability(UserCapability::VOICE)) {
            abort(403);
        }
    }
}
