<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Conversations\PersonalChatSurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisConfirmationController extends Controller
{
    public function __construct(
        private readonly PersonalChatSurfaceService $chats,
    ) {}

    public function confirm(Request $request, string $confirmation): JsonResponse
    {
        return $this->resolve($request, $confirmation, true);
    }

    public function cancel(Request $request, string $confirmation): JsonResponse
    {
        return $this->resolve($request, $confirmation, false);
    }

    private function resolve(Request $request, string $confirmation, bool $confirm): JsonResponse
    {
        $validated = $request->validate([
            'client_message_id' => ['required', 'uuid'],
        ]);

        return response()->json(
            $this->chats->resolveConfirmation(
                $request->user(),
                $confirmation,
                $confirm,
                $validated['client_message_id'],
            ),
        );
    }
}
