<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Knowledge\Exceptions\KnowledgeException;
use App\Services\Knowledge\KnowledgeRetriever;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisKnowledgeController extends Controller
{
    public function __construct(
        private readonly KnowledgeRetriever $knowledge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertKnowledge($user);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'type' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            return response()->json($this->knowledge->workspaceIndex(
                $user,
                $validated['q'] ?? null,
                $validated['type'] ?? null,
            ));
        } catch (KnowledgeException $exception) {
            return response()->json(['error' => $exception->error], $exception->error === 'not_found' ? 404 : 403);
        }
    }

    public function show(Request $request, int $entity): JsonResponse
    {
        $user = $request->user();
        $this->assertKnowledge($user);

        try {
            return response()->json($this->knowledge->getEntity($user, $entity));
        } catch (KnowledgeException $exception) {
            return response()->json(['error' => $exception->error], $exception->error === 'not_found' ? 404 : 403);
        }
    }

    private function assertKnowledge($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            abort(403);
        }
    }
}
