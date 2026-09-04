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
use Illuminate\Validation\Rules\Password;
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

        return redirect()->route($this->named($request, 'chats.show'), $conversation);
    }

    public function show(Request $request, int $conversation): Response
    {
        $user = $request->user();
        $user->loadMissing('aiSettings');
        $current = $this->chats->ensureOwned($user, $conversation);
        $page = $this->chats->page($current);
        $settings = $this->chats->personalSettings($user);
        $owner = $user->isOwner();

        return Inertia::render($this->page($request), [
            'surface' => $this->surface($request),
            'capabilities' => $this->chats->uiCapabilities($user),
            'settings' => $settings,
            'user' => $this->chats->userProfile($user),
            'conversations' => $this->chats->sidebar($user, (int) $current->id),
            'conversation' => [
                'id' => $current->id,
                'title' => $current->title,
            ],
            'messages' => $page['messages'],
            'hasMore' => $page['has_more'],
            'oldestId' => $page['oldest_id'],
            'context' => $owner
                ? $this->context->compact($user, $current)
                : ['settings' => $settings],
            'chatAttachments' => ChatAttachmentConfig::publicLimits(),
            'jarvisStorage' => StoredFileConfig::publicLimits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $conversation = $this->chats->createChat($request->user());

        return redirect()->route($this->named($request, 'chats.show'), $conversation);
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

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $request->user()->forceFill([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
        ])->save();

        return back()->with('success', 'Profile saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
        ])->save();

        return back()->with('success', 'Password updated.');
    }

    private function surface(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return str_starts_with($name, 'chat.') ? 'chat' : 'jarvis';
    }

    private function named(Request $request, string $name): string
    {
        return $this->surface($request).'.'.$name;
    }

    private function page(Request $request): string
    {
        return $this->surface($request) === 'chat' ? 'Chat/Workspace' : 'Jarvis/Workspace';
    }
}
