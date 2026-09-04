<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Services\Conversations\PersonalChatSurfaceService;
use App\Services\Storage\Exceptions\StoredFileException;
use App\Services\Storage\StoredFileConfig;
use App\Services\Storage\StoredFileService;
use App\Services\Workspace\OwnerWorkspaceContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JarvisStorageController extends Controller
{
    public function __construct(
        private readonly StoredFileService $files,
        private readonly PersonalChatSurfaceService $chats,
        private readonly OwnerWorkspaceContextService $context,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $conversation = $this->chats->latestOrDefault($user);
        $query = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $this->files->paginate($user, $query !== '' ? $query : null, $page);

        return Inertia::render('Jarvis/Storage/Index', [
            'user' => $this->chats->userProfile($user),
            'conversations' => $this->chats->sidebar($user),
            'context' => $this->context->compact($user, $conversation),
            'jarvisStorage' => StoredFileConfig::publicLimits(),
            'query' => $query,
            'files' => $paginator->getCollection()
                ->map(fn (StoredFile $file): array => $this->files->publicCard($file))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function show(Request $request, string $file): Response
    {
        $user = $request->user();
        $row = $this->files->ownedByPublicId($user, $file);
        $conversation = $this->chats->latestOrDefault($user);
        $offset = max(0, (int) $request->query('offset', 0));
        $preview = $row->isReady() ? $this->files->previewText($row, $offset) : '';
        $limit = StoredFileConfig::directPreviewChars();

        return Inertia::render('Jarvis/Storage/Show', [
            'user' => $this->chats->userProfile($user),
            'conversations' => $this->chats->sidebar($user),
            'context' => $this->context->compact($user, $conversation),
            'jarvisStorage' => StoredFileConfig::publicLimits(),
            'file' => array_merge($this->files->publicCard($row), [
                'original_name' => $row->original_name,
                'processed_at' => optional($row->processed_at)?->toIso8601String(),
                'error' => is_array($row->metadata) ? ($row->metadata['error'] ?? null) : null,
            ]),
            'preview' => [
                'text' => $preview,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => $row->isReady() && ($offset + mb_strlen($preview)) < (int) $row->extracted_chars,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $maxFiles = StoredFileConfig::maxFilesPerUpload();
        $maxKilobytes = StoredFileConfig::maxFileSizeKilobytes();

        $request->validate([
            'files' => ['required', 'array', 'max:'.$maxFiles],
            'files.*' => ['file', 'max:'.$maxKilobytes],
            'client_upload_id' => ['nullable', 'string', 'max:64'],
        ]);

        $uploads = [];

        foreach ($request->file('files', []) as $file) {
            if ($file instanceof UploadedFile) {
                $uploads[] = $file;
            }
        }

        if ($uploads === []) {
            throw ValidationException::withMessages([
                'files' => 'Выберите файл.',
            ]);
        }

        try {
            $this->files->upload(
                $request->user(),
                $uploads,
                filled($request->input('client_upload_id')) ? (string) $request->input('client_upload_id') : null,
            );
        } catch (StoredFileException $exception) {
            throw ValidationException::withMessages([
                'files' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('jarvis.storage.index')->with('success', 'Файл загружен в Storage.');
    }

    public function update(Request $request, string $file): RedirectResponse
    {
        $row = $this->files->ownedByPublicId($request->user(), $file);
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
        ]);

        $this->files->rename($request->user(), $row, $validated['display_name']);

        return back()->with('success', 'Имя файла обновлено.');
    }

    public function destroy(Request $request, string $file): RedirectResponse
    {
        $row = $this->files->ownedByPublicId($request->user(), $file);
        $this->files->delete($request->user(), $row);

        return redirect()->route('jarvis.storage.index')->with('success', 'Файл удалён из Storage.');
    }

    public function download(Request $request, string $file): StreamedResponse
    {
        $row = $this->files->ownedByPublicId($request->user(), $file);

        return $this->files->download($request->user(), $row);
    }
}
