<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\KnowledgeEntityType;
use App\Enums\SynthesisType;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeEntity;
use App\Models\Project;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\Exceptions\SynthesisException;
use App\Services\Synthesis\SynthesisClock;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class JarvisSynthesisController extends Controller
{
    public function __construct(
        private readonly CrossSourceSynthesisService $synthesis,
        private readonly SynthesisClock $clock = new SynthesisClock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertSynthesis($user);

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:48'],
            'project_id' => ['nullable', 'integer'],
            'entity_id' => ['nullable', 'integer'],
            'window' => ['nullable', 'string', 'max:16'],
        ]);

        $type = SynthesisType::tryFromLoose($validated['type'] ?? 'attention_needed') ?? SynthesisType::AttentionNeeded;
        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;

        if ($projectId !== null && ! $user->canUseCapability(UserCapability::PROJECTS)) {
            abort(403);
        }

        return $this->respond($request, new SynthesisScope(
            user: $user,
            type: $type,
            projectId: $projectId,
            entityId: isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
            windowDays: $this->clock->parseWindowDays($validated['window'] ?? 7),
            withNarrative: false,
        ));
    }

    public function project(Request $request, int $project): JsonResponse
    {
        $user = $request->user();
        $this->assertSynthesis($user);

        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            abort(403);
        }

        $owned = Project::query()->where('user_id', $user->id)->whereKey($project)->first();

        if ($owned === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return $this->respond($request, new SynthesisScope(
            user: $user,
            type: SynthesisType::ProjectStatus,
            projectId: (int) $owned->id,
            withNarrative: false,
        ));
    }

    public function entity(Request $request, int $entity): JsonResponse
    {
        $user = $request->user();
        $this->assertSynthesis($user);

        $owned = KnowledgeEntity::query()->where('user_id', $user->id)->whereKey($entity)->first();

        if ($owned === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $type = $owned->type === KnowledgeEntityType::Person
            ? SynthesisType::PersonStatus
            : SynthesisType::ProjectStatus;

        return $this->respond($request, new SynthesisScope(
            user: $user,
            type: $type,
            entityId: (int) $owned->id,
            projectId: $owned->project_id,
            withNarrative: false,
        ));
    }

    /**
     * Unexpected failures return a bounded error code and log only non-sensitive identifiers, so a
     * failed manual check can be reported without copying conversation or provider payloads.
     */
    private function respond(Request $request, SynthesisScope $scope): JsonResponse
    {
        try {
            return response()->json($this->synthesis->synthesize($scope)->toArray());
        } catch (SynthesisException $exception) {
            return response()->json(['error' => $exception->error], $exception->error === 'not_found' ? 404 : 403);
        } catch (Throwable $exception) {
            Log::error('synthesis request failed', [
                'route' => $request->route()?->getName(),
                'user_id' => (int) $scope->user->id,
                'type' => $scope->type->value,
                'project_id' => $scope->projectId,
                'entity_id' => $scope->entityId,
                'exception' => $exception::class,
            ]);

            return response()->json(['error' => 'synthesis_failed'], 500);
        }
    }

    private function assertSynthesis($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            abort(403);
        }
    }
}
