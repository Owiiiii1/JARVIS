<?php

namespace App\Http\Controllers;

use App\Enums\MessageChannel;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Conversations\MessageHistoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CabinetChatController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationTurnService $turns,
        private readonly MessageHistoryService $history,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $conversation = $this->conversations->getOrCreateDefault($request->user());

        return redirect()->route('cabinet.chats.show', $conversation);
    }

    public function show(Request $request, int $conversation): Response
    {
        $user = $request->user();
        $current = $this->conversations->ensureOwned($user, $conversation);
        $page = $this->history->page($current);

        return Inertia::render('Cabinet/Chat', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'timezone' => $user->timezone,
            ],
            'conversations' => $this->sidebar($user, (int) $current->id),
            'conversation' => [
                'id' => $current->id,
                'title' => $current->title,
            ],
            'messages' => $page['messages'],
            'hasMore' => $page['has_more'],
            'oldestId' => $page['oldest_id'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $conversation = $this->conversations->createPersonal(
            $request->user(),
            ConversationService::NEW_CHAT_TITLE,
        );

        return redirect()->route('cabinet.chats.show', $conversation);
    }

    public function update(Request $request, int $conversation): RedirectResponse
    {
        $user = $request->user();
        $current = $this->conversations->ensureOwned($user, $conversation);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.ConversationService::TITLE_MAX_LENGTH],
        ]);

        try {
            $this->conversations->rename($user, $current, $validated['title']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'title' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    public function storeMessage(Request $request, int $conversation): JsonResponse
    {
        $user = $request->user();
        $current = $this->conversations->ensureOwned($user, $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'client_message_id' => ['required', 'uuid'],
        ]);

        try {
            $turn = $this->turns->handleUserMessage(
                $user,
                $current,
                $validated['body'],
                new ChannelContext(
                    channel: MessageChannel::Web,
                    channelMessageId: $validated['client_message_id'],
                ),
            );
        } catch (AuthorizationException) {
            abort(404);
        }

        return response()->json([
            'inbound' => $this->history->toArray($turn->inbound),
            'assistant' => $turn->assistantMessage !== null
                ? $this->history->toArray($turn->assistantMessage)
                : null,
            'error' => $turn->errorText,
            'duplicate' => ! $turn->created,
            'conversation' => [
                'id' => $current->fresh()->id,
                'title' => $current->fresh()->title,
                'last_activity_at' => optional($current->fresh()->last_activity_at)?->toIso8601String(),
            ],
        ]);
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        $current = $this->conversations->ensureOwned($request->user(), $conversation);

        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->history->page(
            $current,
            isset($validated['before_id']) ? (int) $validated['before_id'] : null,
            (int) ($validated['limit'] ?? MessageHistoryService::PAGE_SIZE),
        );

        return response()->json($page);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sidebar($user, ?int $currentId = null): array
    {
        return $this->conversations->listForUser($user, ConversationService::CABINET_LIST_LIMIT)
            ->map(static fn ($conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                'current' => $currentId !== null && (int) $conversation->id === $currentId,
            ])
            ->values()
            ->all();
    }
}
