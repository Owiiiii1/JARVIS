<?php

namespace App\Http\Controllers;

use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Services\Groups\Exceptions\GroupAnalysisException;
use App\Services\Groups\Exceptions\TelegramGroupException;
use App\Services\Groups\GroupAnalysisRunService;
use App\Services\Groups\GroupKnowledgePresenter;
use App\Services\Groups\GroupMessagingService;
use App\Services\Groups\TelegramGroupDiscoveryService;
use App\Services\Groups\TelegramGroupHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TelegramGroupController extends Controller
{
    public function __construct(
        private readonly TelegramGroupHistoryService $history,
        private readonly GroupMessagingService $messaging,
        private readonly TelegramGroupDiscoveryService $discovery,
        private readonly GroupAnalysisRunService $analysisRuns,
        private readonly GroupKnowledgePresenter $knowledge,
    ) {}

    public function index(Request $request): Response
    {
        return $this->list($request, archived: false);
    }

    public function archive(Request $request): Response
    {
        return $this->list($request, archived: true);
    }

    public function show(Request $request, TelegramGroup $telegramGroup): Response
    {
        $this->authorize('view', $telegramGroup);
        $this->authorize('viewKnowledge', $telegramGroup);

        $owner = $this->discovery->owner();
        $page = $this->history->page($telegramGroup);

        return Inertia::render('TelegramGroups/Show', [
            'group' => $this->detailPayload($telegramGroup, $owner->timezone),
            'messages' => $page['messages'],
            'hasMore' => $page['has_more'],
            'oldestId' => $page['oldest_id'],
            'knowledge' => $this->knowledge->knowledge($telegramGroup),
            'analysisRuns' => $this->knowledge->runs($telegramGroup),
        ]);
    }

    public function update(Request $request, TelegramGroup $telegramGroup): RedirectResponse
    {
        $this->authorize('update', $telegramGroup);

        $validated = $request->validate([
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $this->messaging->updateTimezone(
                $request->user(),
                $telegramGroup,
                $validated['timezone'] ?? null,
            );
        } catch (TelegramGroupException $exception) {
            throw ValidationException::withMessages([
                'timezone' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    public function messages(Request $request, TelegramGroup $telegramGroup): JsonResponse
    {
        $this->authorize('view', $telegramGroup);

        $beforeId = $request->integer('before_id') ?: null;
        $page = $this->history->page($telegramGroup, $beforeId);

        return response()->json([
            'messages' => $page['messages'],
            'has_more' => $page['has_more'],
            'oldest_id' => $page['oldest_id'],
        ]);
    }

    public function storeMessage(Request $request, TelegramGroup $telegramGroup): JsonResponse
    {
        $this->authorize('send', $telegramGroup);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $message = $this->messaging->send($request->user(), $telegramGroup, $validated['body']);
        } catch (TelegramGroupException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error' => $exception->error,
            ], $exception->error === 'forbidden' ? 403 : 422);
        }

        return response()->json([
            'message' => $this->history->toArray($message),
        ]);
    }

    public function storeAnalysis(Request $request, TelegramGroup $telegramGroup): RedirectResponse
    {
        $this->authorize('analyze', $telegramGroup);

        $validated = $request->validate([
            'preset' => ['required', Rule::in(['today', 'yesterday', 'last_7_days', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:preset,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:preset,custom'],
        ]);

        try {
            $range = $this->analysisRuns->rangeForPreset(
                $telegramGroup,
                $validated['preset'],
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            );
            $this->analysisRuns->queue($request->user(), $telegramGroup, $range['from'], $range['to']);
        } catch (GroupAnalysisException $exception) {
            throw ValidationException::withMessages([
                'preset' => $exception->getMessage(),
            ]);
        }

        return back()->with('analysis', 'queued');
    }

    public function retryAnalysis(Request $request, TelegramGroup $telegramGroup, TelegramGroupAnalysisRun $run): RedirectResponse
    {
        $this->authorize('analyze', $telegramGroup);

        if ((int) $run->telegram_group_id !== (int) $telegramGroup->id) {
            abort(404);
        }

        try {
            $this->analysisRuns->retry($request->user(), $run);
        } catch (GroupAnalysisException $exception) {
            throw ValidationException::withMessages([
                'run' => $exception->getMessage(),
            ]);
        }

        return back()->with('analysis', 'queued');
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(TelegramGroup $group, ?string $ownerTimezone): array
    {
        return [
            'id' => $group->id,
            'title' => $group->title ?: 'Untitled group',
            'chat_type' => $group->chat_type,
            'status' => $group->status->value,
            'message_count' => (int) $group->message_count,
            'first_seen_at' => optional($group->first_seen_at)?->toIso8601String(),
            'last_message_at' => optional($group->last_message_at)?->toIso8601String(),
            'timezone' => $group->timezone,
            'effective_timezone' => $group->effectiveTimezone($ownerTimezone),
            'timezone_is_fallback' => ! filled($group->timezone),
            'mode' => $group->mode(),
            'telegram_chat_id' => $group->telegram_chat_id,
            'archived' => $group->isArchived(),
        ];
    }

    private function list(Request $request, bool $archived): Response
    {
        $this->authorize('viewAny', TelegramGroup::class);

        $owner = $this->discovery->owner();
        $query = $archived
            ? TelegramGroup::query()->archived()
            : TelegramGroup::query()->active();

        $groups = $query
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TelegramGroup $group): array => $this->listPayload($group, $owner->timezone));

        return Inertia::render('TelegramGroups/Index', [
            'groups' => $groups,
            'archived' => $archived,
            'archivedCount' => TelegramGroup::query()->archived()->count(),
            'activeCount' => TelegramGroup::query()->active()->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(TelegramGroup $group, ?string $ownerTimezone): array
    {
        return [
            ...$this->listPayload($group, $ownerTimezone),
            'username' => $group->username,
        ];
    }
}
