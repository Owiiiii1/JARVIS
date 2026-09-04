<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\ChatAttachments\ChatAttachmentAccessService;
use App\Services\Conversations\PersonalChatSurfaceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JarvisAttachmentController extends Controller
{
    public function __construct(
        private readonly PersonalChatSurfaceService $chats,
        private readonly ChatAttachmentAccessService $access,
    ) {}

    public function preview(Request $request, int $conversation, int $attachment): StreamedResponse
    {
        $current = $this->chats->ensureOwned($request->user(), $conversation);
        $row = $this->access->owned($request->user(), $current, $attachment);

        return $this->access->stream($row, thumbnail: true);
    }

    public function show(Request $request, int $conversation, int $attachment): StreamedResponse
    {
        $current = $this->chats->ensureOwned($request->user(), $conversation);
        $row = $this->access->owned($request->user(), $current, $attachment);

        return $this->access->stream($row, thumbnail: false);
    }
}
