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

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $user,
                type: $type,
                projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                entityId: isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
                windowDays: $this->clock->parseWindowDays($validated['window'] ?? 7),
                withNarrative: false,
            ));
        } catch (SynthesisException $exception) {
            return response()->json(['error' => $exception->error], $exception->error === 'not_found' ? 404 : 403);
        }

        return response()->json($result->toArray());
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

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::ProjectStatus,
                projectId: (int) $owned->id,
                withNarrative: false,
            ));
        } catch (SynthesisException $exception) {
            return response()->json(['error' => $exception->error], $exception->error === 'not_found' ? 404 : 403);
        }

        return response()->json($result->toArray());
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

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $user,
                type: $type,
                entityId: (int) $owned->id,
                projectId: $owned->project_id,
                withNarrative: false,
            ));
        } catch (SynthesisException $exception) {
            return response()->json(['error' => $exception->error], $exception->error === 'not_found' ? 404 : 403);
        }

        return response()->json($result->toArray());
    }

    private function assertSynthesis($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            abort(403);
        }
    }
}
