<?php

namespace App\Services\ChatAttachments;

use App\Models\Message;
use App\Models\MessageAttachment;

final class ChatAttachmentPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forMessage(Message $message): array
    {
        $message->loadMissing('attachments');

        return $message->attachments
            ->map(fn (MessageAttachment $attachment): array => $this->toArray($attachment, (int) $message->conversation_id))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(MessageAttachment $attachment, int $conversationId): array
    {
        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'mime_type' => $attachment->mime_type,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'size_bytes' => $attachment->size_bytes,
            'preview_url' => route('jarvis.attachments.preview', [
                'conversation' => $conversationId,
                'attachment' => $attachment->id,
            ]),
            'view_url' => route('jarvis.attachments.show', [
                'conversation' => $conversationId,
                'attachment' => $attachment->id,
            ]),
        ];
    }
}
