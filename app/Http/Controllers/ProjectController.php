<?php

namespace App\Http\Controllers;

use App\Enums\MemoryStatus;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Project;
use App\Models\Topic;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $user = $request->user();

        return Inertia::render('Projects/Index', [
            'projects' => $this->projects->listForOwner($user)->map(static fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'conversations_count' => (int) $project->conversations_count,
                'topics_count' => (int) $project->topics_count,
                'memories_count' => (int) $project->memories_count,
                'updated_at' => optional($project->updated_at)?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:'.(int) config('projects.description_max')],
        ]);

        try {
            $project = $this->projects->create(
                $request->user(),
                $validated['name'],
                $validated['description'] ?? null,
            );
        } catch (ProjectException $exception) {
            return back()->withErrors(['name' => $this->messageFor($exception)]);
        }

        return redirect()->route('projects.show', $project);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorizeOwned($request, $project, 'view');

        $user = $request->user();
        $project->load(['conversations:id,user_id,title,last_activity_at', 'topics:id,user_id,name,status', 'memories:id,user_id,content,kind,confidence,status']);

        $attachedConversationIds = $project->conversations->pluck('id');
        $attachedTopicIds = $project->topics->pluck('id');
        $attachedMemoryIds = $project->memories->pluck('id');

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status->value,
                'updated_at' => optional($project->updated_at)?->toIso8601String(),
                'conversations' => $project->conversations->map(static fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                ])->all(),
                'topics' => $project->topics->map(static fn (Topic $topic): array => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'status' => $topic->status->value,
                ])->all(),
                'memories' => $project->memories->map(static fn (Memory $memory): array => [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'kind' => $memory->kind->value,
                    'confidence' => $memory->confidence,
                    'status' => $memory->status->value,
                ])->all(),
            ],
            'availableConversations' => Conversation::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $attachedConversationIds)
                ->orderByRaw('last_activity_at IS NULL')
                ->orderByDesc('last_activity_at')
                ->limit(50)
                ->get(['id', 'title', 'last_activity_at'])
                ->map(static fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'title' => $conversation->title,
                    'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                ])
                ->all(),
            'availableTopics' => Topic::query()
                ->where('user_id', $user->id)
                ->whereNotIn('id', $attachedTopicIds)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'status'])
                ->map(static fn (Topic $topic): array => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'status' => $topic->status->value,
                ])
                ->all(),
            'availableMemories' => Memory::query()
                ->where('user_id', $user->id)
                ->where('status', MemoryStatus::Active)
                ->whereNotIn('id', $attachedMemoryIds)
                ->orderByDesc('confidence')
                ->limit(50)
                ->get(['id', 'content', 'kind', 'confidence'])
                ->map(static fn (Memory $memory): array => [
                    'id' => $memory->id,
                    'content' => $memory->content,
                    'kind' => $memory->kind->value,
                    'confidence' => $memory->confidence,
                ])
                ->all(),
            'descriptionMax' => (int) config('projects.description_max'),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'update');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:'.(int) config('projects.description_max')],
        ]);

        try {
            $this->projects->update(
                $request->user(),
                $project,
                $validated['name'],
                $validated['description'] ?? null,
            );
        } catch (ProjectException $exception) {
            return back()->withErrors(['name' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function archive(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'archive');
        $this->projects->archive($request->user(), $project);

        return back();
    }

    public function restore(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'restore');
        $this->projects->restore($request->user(), $project);

        return back();
    }

    public function attachConversation(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['conversation_id' => ['required', 'integer']]);
        $conversation = Conversation::query()->findOrFail($validated['conversation_id']);

        try {
            $this->projects->attachConversation($request->user(), $project, $conversation);
        } catch (ProjectException $exception) {
            return back()->withErrors(['conversation_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachConversation(Request $request, Project $project, Conversation $conversation): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachConversation($request->user(), $project, $conversation);

        return back();
    }

    public function attachTopic(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['topic_id' => ['required', 'integer']]);
        $topic = Topic::query()->findOrFail($validated['topic_id']);

        try {
            $this->projects->attachTopic($request->user(), $project, $topic);
        } catch (ProjectException $exception) {
            return back()->withErrors(['topic_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachTopic(Request $request, Project $project, Topic $topic): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachTopic($request->user(), $project, $topic);

        return back();
    }

    public function attachMemory(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $validated = $request->validate(['memory_id' => ['required', 'integer']]);
        $memory = Memory::query()->findOrFail($validated['memory_id']);

        try {
            $this->projects->attachMemory($request->user(), $project, $memory);
        } catch (ProjectException $exception) {
            return back()->withErrors(['memory_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachMemory(Request $request, Project $project, Memory $memory): RedirectResponse
    {
        $this->authorizeOwned($request, $project, 'attach');
        $this->projects->detachMemory($request->user(), $project, $memory);

        return back();
    }

    private function authorizeOwned(Request $request, Project $project, string $ability): void
    {
        if ((int) $project->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize($ability, $project);
    }

    private function messageFor(ProjectException $exception): string
    {
        return match ($exception->error) {
            'duplicate_name' => 'A project with this name already exists.',
            'invalid_name' => 'Project name is required.',
            'foreign_conversation' => 'That conversation cannot be attached.',
            'foreign_topic' => 'That topic cannot be attached.',
            'foreign_memory' => 'That memory cannot be attached.',
            default => 'Unable to update the project.',
        };
    }
}
