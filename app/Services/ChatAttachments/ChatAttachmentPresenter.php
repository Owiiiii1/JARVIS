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
        $purged = $attachment->isPurged();
        $summary = $attachment->summary_text;
        $max = ChatAttachmentConfig::summaryMaxChars();

        if (is_string($summary) && mb_strlen($summary) > $max) {
            $summary = mb_substr($summary, 0, $max);
        }

        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'mime_type' => $attachment->mime_type,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'size_bytes' => $attachment->size_bytes,
            'retention_class' => $attachment->retention_class?->value,
            'expires_at' => optional($attachment->expires_at)?->toIso8601String(),
            'summary_status' => $attachment->summary_status?->value,
            'summary_text' => $summary,
            'purged' => $purged,
            'preview_url' => $purged ? null : route('jarvis.attachments.preview', [
                'conversation' => $conversationId,
                'attachment' => $attachment->id,
            ]),
            'view_url' => $purged ? null : route('jarvis.attachments.show', [
                'conversation' => $conversationId,
                'attachment' => $attachment->id,
            ]),
        ];
    }
}
