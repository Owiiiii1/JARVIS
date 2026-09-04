<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\VoiceOrigin;
use App\Http\Controllers\Controller;
use App\Models\VoiceSession;
use App\Services\Conversations\PersonalChatSurfaceService;
use App\Services\Voice\Exceptions\VoiceException;
use App\Services\Voice\VoiceRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class JarvisVoiceController extends Controller
{
    public function __construct(
        private readonly VoiceRuntimeService $runtime,
        private readonly PersonalChatSurfaceService $chats,
    ) {}

    public function store(Request $request, int $conversation): JsonResponse
    {
        $user = $request->user();
        $current = $this->chats->ensureOwned($user, $conversation);
        $validated = $request->validate([
            'origin' => ['nullable', 'in:web,desktop,mobile'],
        ]);

        try {
            $snapshot = $this->runtime->start(
                $user,
                $current,
                VoiceOrigin::normalize($validated['origin'] ?? 'web'),
            );
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }

        return response()->json($snapshot->toArray(), 201);
    }

    public function show(Request $request, VoiceSession $session): JsonResponse
    {
        try {
            return response()->json($this->runtime->snapshot($request->user(), $session)->toArray());
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    public function listen(Request $request, VoiceSession $session): JsonResponse
    {
        try {
            return response()->json($this->runtime->listen($request->user(), $session)->toArray());
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    public function audio(Request $request, VoiceSession $session): JsonResponse
    {
        $maxKilobytes = (int) ceil(max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000)) / 1024);
        $request->merge([
            'is_final' => $request->boolean('is_final'),
        ]);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:'.$maxKilobytes],
            'sequence' => ['required', 'integer', 'min:1'],
            'is_final' => ['required', 'boolean'],
            'client_message_id' => ['required', 'uuid'],
            'sample_rate' => ['nullable', 'integer', 'min:8000', 'max:48000'],
            'channels' => ['nullable', 'integer', 'min:1', 'max:2'],
            'duration_ms' => ['nullable', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        $file = $request->file('audio');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'audio' => 'Audio file is required.',
            ]);
        }

        try {
            $snapshot = $this->runtime->processUtterance(
                $request->user(),
                $session,
                $file,
                (int) $validated['sequence'],
                (bool) $validated['is_final'],
                (string) $validated['client_message_id'],
                isset($validated['sample_rate']) ? (int) $validated['sample_rate'] : null,
                (int) ($validated['channels'] ?? 1),
                isset($validated['duration_ms']) ? (int) $validated['duration_ms'] : null,
                $validated['language'] ?? null,
            );
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }

        return response()->json($snapshot->toArray());
    }

    public function interrupt(Request $request, VoiceSession $session): JsonResponse
    {
        try {
            return response()->json($this->runtime->interrupt($request->user(), $session)->toArray());
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    public function mute(Request $request, VoiceSession $session): JsonResponse
    {
        try {
            return response()->json($this->runtime->mute($request->user(), $session)->toArray());
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    public function resume(Request $request, VoiceSession $session): JsonResponse
    {
        try {
            return response()->json($this->runtime->resume($request->user(), $session)->toArray());
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    public function destroy(Request $request, VoiceSession $session): JsonResponse
    {
        try {
            return response()->json($this->runtime->end($request->user(), $session)->toArray());
        } catch (VoiceException $exception) {
            return $this->error($exception);
        }
    }

    private function error(VoiceException $exception): JsonResponse
    {
        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
        ], $exception->httpStatus);
    }
}
