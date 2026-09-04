<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\UserAiSetting;
use App\Services\ChatAttachments\ChatAttachmentConfig;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\PersonalChatSurfaceService;
use App\Services\Storage\StoredFileConfig;
use App\Services\Workspace\OwnerWorkspaceContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class JarvisWorkspaceController extends Controller
{
    public function __construct(
        private readonly PersonalChatSurfaceService $chats,
        private readonly OwnerWorkspaceContextService $context,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $conversation = $this->chats->latestOrDefault($request->user());

        return redirect()->route('jarvis.chats.show', $conversation);
    }

    public function show(Request $request, int $conversation): Response
    {
        $user = $request->user();
        $user->loadMissing('aiSettings');
        $current = $this->chats->ensureOwned($user, $conversation);
        $page = $this->chats->page($current);

        return Inertia::render('Jarvis/Workspace', [
            'user' => $this->chats->userProfile($user),
            'conversations' => $this->chats->sidebar($user, (int) $current->id),
            'conversation' => [
                'id' => $current->id,
                'title' => $current->title,
            ],
            'messages' => $page['messages'],
            'hasMore' => $page['has_more'],
            'oldestId' => $page['oldest_id'],
            'context' => $this->context->compact($user, $current),
            'chatAttachments' => ChatAttachmentConfig::publicLimits(),
            'jarvisStorage' => StoredFileConfig::publicLimits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $conversation = $this->chats->createChat($request->user());

        return redirect()->route('jarvis.chats.show', $conversation);
    }

    public function update(Request $request, int $conversation): RedirectResponse
    {
        $user = $request->user();
        $current = $this->chats->ensureOwned($user, $conversation);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.ConversationService::TITLE_MAX_LENGTH],
        ]);

        try {
            $this->chats->rename($user, $current, $validated['title']);
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
        $current = $this->chats->ensureOwned($user, $conversation);
        $maxImages = ChatAttachmentConfig::maxImagesPerMessage();
        $maxKilobytes = ChatAttachmentConfig::maxFileSizeKilobytes();
        $maxFiles = StoredFileConfig::maxFilesPerUpload();
        $maxFileKilobytes = StoredFileConfig::maxFileSizeKilobytes();

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:8000'],
            'client_message_id' => ['required', 'uuid'],
            'images' => ['sometimes', 'array', 'max:'.$maxImages],
            'images.*' => ['file', 'max:'.$maxKilobytes],
            'files' => ['sometimes', 'array', 'max:'.$maxFiles],
            'files.*' => ['file', 'max:'.$maxFileKilobytes],
        ]);

        $images = [];

        foreach ($request->file('images', []) as $file) {
            if ($file instanceof UploadedFile) {
                $images[] = $file;
            }
        }

        $documents = [];

        foreach ($request->file('files', []) as $file) {
            if ($file instanceof UploadedFile) {
                $documents[] = $file;
            }
        }

        $body = trim((string) ($validated['body'] ?? ''));

        if ($body === '' && $images === [] && $documents === []) {
            throw ValidationException::withMessages([
                'body' => 'Нужен текст, изображение или файл.',
            ]);
        }

        return response()->json(
            $this->chats->sendTurn(
                $user,
                $current,
                $body,
                $validated['client_message_id'],
                $images,
                $documents,
            ),
        );
    }

    public function olderMessages(Request $request, int $conversation): JsonResponse
    {
        $current = $this->chats->ensureOwned($request->user(), $conversation);

        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->chats->page(
            $current,
            isset($validated['before_id']) ? (int) $validated['before_id'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : null,
        ));
    }

    public function updateGeneralPrompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'general_prompt' => ['nullable', 'string', 'max:10000'],
        ]);

        $prompt = filled($validated['general_prompt'] ?? null)
            ? trim((string) $validated['general_prompt'])
            : null;

        UserAiSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['general_prompt' => $prompt === '' ? null : $prompt],
        );

        return back()->with('success', 'General Prompt saved.');
    }
}
