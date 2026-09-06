<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisTaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertTasks($user);

        try {
            return response()->json($this->tasks->panelFor($user));
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertTasks($user);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:4000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'due_at_local' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'project_id' => ['nullable', 'integer'],
        ]);

        try {
            $timezone = $validated['timezone'] ?? (string) ($user->timezone ?: 'UTC');
            $dueAt = filled($validated['due_at_local'] ?? null)
                ? $this->tasks->localWallTimeToUtc((string) $validated['due_at_local'], $timezone)
                : null;
            $this->tasks->create(
                user: $user,
                title: $validated['title'],
                description: $validated['description'] ?? null,
                priority: isset($validated['priority']) ? $this->tasks->normalizePriority($validated['priority']) : null,
                dueAt: $dueAt,
                timezone: $timezone,
                projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            );

            return $this->panel($user);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function update(Request $request, int $task): JsonResponse
    {
        $user = $request->user();
        $this->assertTasks($user);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:240'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'due_at_local' => ['sometimes', 'nullable', 'string', 'max:64'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $attributes = $validated;

            if (array_key_exists('due_at_local', $validated)) {
                $attributes['due_at'] = $validated['due_at_local'];
            }

            $this->tasks->updateOwned($user, $task, $attributes, (bool) ($validated['force'] ?? false));

            return $this->panel($user);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function start(Request $request, int $task): JsonResponse
    {
        return $this->mutate($request, $task, fn ($user, $id) => $this->tasks->startOwned($user, $id));
    }

    public function complete(Request $request, int $task): JsonResponse
    {
        $user = $request->user();
        $this->assertTasks($user);
        $force = (bool) $request->boolean('force');

        try {
            $this->tasks->completeOwned($user, $task, $force);

            return $this->panel($user);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    public function cancel(Request $request, int $task): JsonResponse
    {
        return $this->mutate($request, $task, fn ($user, $id) => $this->tasks->cancelOwned($user, $id));
    }

    public function reopen(Request $request, int $task): JsonResponse
    {
        return $this->mutate($request, $task, fn ($user, $id) => $this->tasks->reopenOwned($user, $id));
    }

    public function storeSubtask(Request $request, int $task): JsonResponse
    {
        $user = $request->user();
        $this->assertTasks($user);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:240'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $this->tasks->addSubtask($user, $task, $validated['title'], $validated['description'] ?? null);

            return $this->panel($user);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    private function mutate(Request $request, int $task, callable $action): JsonResponse
    {
        $user = $request->user();
        $this->assertTasks($user);

        try {
            $action($user, $task);

            return $this->panel($user);
        } catch (TaskException $exception) {
            return $this->error($exception);
        }
    }

    private function panel($user): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'active_count' => $this->tasks->activeOpenCount($user),
            ...$this->tasks->panelFor($user),
        ]);
    }

    private function error(TaskException $exception): JsonResponse
    {
        $status = match ($exception->error) {
            'not_found' => 404,
            'capability_denied' => 403,
            'open_subtasks' => 409,
            default => 422,
        };

        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
        ], $status);
    }

    private function assertTasks($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::TASKS)) {
            abort(403);
        }
    }
}
