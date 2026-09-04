<?php

namespace App\Services\ChatAttachments;

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ChatAttachmentAccessService
{
    public function owned(User $user, Conversation $conversation, int $attachmentId): MessageAttachment
    {
        if ((int) $conversation->user_id !== (int) $user->id) {
            abort(404);
        }

        $attachment = MessageAttachment::query()
            ->with(['message.conversation'])
            ->whereKey($attachmentId)
            ->first();

        if ($attachment === null) {
            abort(404);
        }

        if ((int) $attachment->user_id !== (int) $user->id) {
            abort(404);
        }

        $message = $attachment->message;

        if ($message === null || (int) $message->conversation_id !== (int) $conversation->id) {
            abort(404);
        }

        if ((int) $message->user_id !== (int) $user->id) {
            abort(404);
        }

        if ((int) ($message->conversation?->user_id) !== (int) $user->id) {
            abort(404);
        }

        return $attachment;
    }

    public function stream(MessageAttachment $attachment, bool $thumbnail = false): StreamedResponse
    {
        $path = $thumbnail ? ($attachment->thumbnailPath() ?? $attachment->storage_path) : $attachment->storage_path;
        $disk = Storage::disk($attachment->storage_disk);

        if (! $disk->exists($path)) {
            abort(404);
        }

        $mime = $thumbnail && $attachment->thumbnailPath() !== null
            ? 'image/jpeg'
            : $attachment->mime_type;

        $downloadName = $attachment->original_name ?: ('attachment-'.$attachment->id);

        return $disk->response(
            $path,
            $downloadName,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
