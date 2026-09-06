<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\WatcherCreatedBy;
use App\Http\Controllers\Controller;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\WatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisWatcherController extends Controller
{
    public function __construct(
        private readonly WatcherService $watchers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertWatchers($user);

        try {
            return response()->json($this->watchers->panelFor($user));
        } catch (WatcherException $exception) {
            return $this->error($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertWatchers($user);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'trigger_type' => ['required', 'string', 'max:48'],
            'source_type' => ['nullable', 'string', 'max:48'],
            'condition_type' => ['required', 'string', 'max:48'],
            'reaction_type' => ['nullable', 'string', 'max:48'],
            'mode' => ['nullable', 'string', 'in:one_shot,recurring'],
            'cooldown_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'task_id' => ['nullable', 'integer'],
            'knowledge_entity_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'array'],
            'condition' => ['nullable', 'array'],
            'reaction_config' => ['nullable', 'array'],
        ]);

        try {
            $this->watchers->create($user, $validated, WatcherCreatedBy::Ui);

            return $this->panel($user);
        } catch (WatcherException $exception) {
            return $this->error($exception);
        }
    }

    public function show(Request $request, int $watcher): JsonResponse
    {
        $user = $request->user();
        $this->assertWatchers($user);

        try {
            $owned = $this->watchers->requireOwned($user, $watcher);

            return response()->json([
                'ok' => true,
                'watcher' => $this->watchers->serialize($owned),
                'occurrences' => $this->watchers->occurrencesFor($user, (int) $owned->id),
            ]);
        } catch (WatcherException $exception) {
            return $this->error($exception);
        }
    }

    public function update(Request $request, int $watcher): JsonResponse
    {
        $user = $request->user();
        $this->assertWatchers($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'trigger_type' => ['sometimes', 'string', 'max:48'],
            'condition_type' => ['sometimes', 'string', 'max:48'],
            'reaction_type' => ['sometimes', 'string', 'max:48'],
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'source' => ['sometimes', 'array'],
            'condition' => ['sometimes', 'array'],
            'reaction_config' => ['sometimes', 'array'],
        ]);

        try {
            $this->watchers->updateOwned($user, $watcher, $validated);

            return $this->panel($user);
        } catch (WatcherException $exception) {
            return $this->error($exception);
        }
    }

    public function pause(Request $request, int $watcher): JsonResponse
    {
        return $this->mutate($request, $watcher, fn ($user, $id) => $this->watchers->pauseOwned($user, $id));
    }

    public function resume(Request $request, int $watcher): JsonResponse
    {
        return $this->mutate($request, $watcher, fn ($user, $id) => $this->watchers->resumeOwned($user, $id));
    }

    public function cancel(Request $request, int $watcher): JsonResponse
    {
        return $this->mutate($request, $watcher, fn ($user, $id) => $this->watchers->cancelOwned($user, $id));
    }

    public function occurrences(Request $request, int $watcher): JsonResponse
    {
        $user = $request->user();
        $this->assertWatchers($user);

        try {
            return response()->json([
                'ok' => true,
                'occurrences' => $this->watchers->occurrencesFor($user, $watcher),
            ]);
        } catch (WatcherException $exception) {
            return $this->error($exception);
        }
    }

    private function mutate(Request $request, int $watcher, callable $action): JsonResponse
    {
        $user = $request->user();
        $this->assertWatchers($user);

        try {
            $action($user, $watcher);

            return $this->panel($user);
        } catch (WatcherException $exception) {
            return $this->error($exception);
        }
    }

    private function panel($user): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'active_count' => $this->watchers->activeCount($user),
            ...$this->watchers->panelFor($user),
        ]);
    }

    private function error(WatcherException $exception): JsonResponse
    {
        $status = match ($exception->error) {
            'not_found' => 404,
            'capability_denied' => 403,
            default => 422,
        };

        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
            'candidates' => $exception->candidates,
        ], $status);
    }

    private function assertWatchers($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::WATCHERS)) {
            abort(403);
        }
    }
}
