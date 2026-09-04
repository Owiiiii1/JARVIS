<?php

namespace App\Services\ChatAttachments;

use App\Enums\AttachmentSummaryStatus;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Ai\DTO\AiContentPart;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ChatAttachmentVisionLoader
{
    /**
     * Current-turn image parts only. Historical attachments stay out of the payload.
     *
     * @return list<AiContentPart>
     */
    public function currentTurnParts(Message $message, string $extraText = ''): array
    {
        $message->loadMissing('attachments');

        $parts = [];
        $body = trim((string) $message->body);

        if ($body !== '') {
            $parts[] = AiContentPart::text($body);
        }

        $extra = trim($extraText);

        if ($extra !== '') {
            $parts[] = AiContentPart::text($extra);
        }

        $budget = ChatAttachmentConfig::maxTotalUploadBytes();
        $used = 0;

        foreach ($message->attachments as $attachment) {
            if (! $attachment instanceof MessageAttachment || ! $attachment->isImage() || $attachment->isPurged()) {
                continue;
            }

            $bytes = $this->readBytes($attachment);

            if ($bytes === null) {
                continue;
            }

            $size = strlen($bytes);

            if ($used + $size > $budget) {
                throw new Exceptions\ChatAttachmentException(
                    'vision_payload_too_large',
                    'Изображения слишком большие для отправки в модель.',
                );
            }

            $used += $size;
            $parts[] = AiContentPart::image(
                $attachment->mime_type,
                base64_encode($bytes),
                (int) $attachment->id,
                $size,
            );
        }

        if ($parts === []) {
            $parts[] = AiContentPart::text('Пользователь отправил изображение.');
        }

        try {
            Log::info('chat vision payload prepared', [
                'message_id' => $message->id,
                'image_count' => count(array_filter(
                    $parts,
                    static fn (AiContentPart $part): bool => $part->isImage(),
                )),
                'size_bytes' => $used,
            ]);
        } catch (Throwable) {
        }

        return $parts;
    }

    public function historicalPlaceholder(Message $message): ?string
    {
        $message->loadMissing('attachments');
        $images = $message->attachments->filter(
            static fn (MessageAttachment $attachment): bool => $attachment->isImage(),
        );

        if ($images->isEmpty()) {
            return null;
        }

        $lines = [];

        foreach ($images as $attachment) {
            $summary = trim((string) ($attachment->summary_text ?? ''));

            if ($attachment->isPurged()) {
                $lines[] = $summary !== ''
                    ? '[Previous screenshot summary: '.$this->bounded($summary).']'
                    : '[Previous screenshot summary unavailable]';

                continue;
            }

            if ($attachment->summary_status === AttachmentSummaryStatus::Ready && $summary !== '') {
                $lines[] = '[Previous screenshot summary: '.$this->bounded($summary).']';

                continue;
            }

            $lines[] = '[1 image attached]';
        }

        return implode("\n", $lines);
    }

    private function bounded(string $text): string
    {
        $max = ChatAttachmentConfig::summaryMaxChars();

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private function readBytes(MessageAttachment $attachment): ?string
    {
        if ($attachment->storage_path === '') {
            return null;
        }

        try {
            $contents = Storage::disk($attachment->storage_disk)->get($attachment->storage_path);
        } catch (Throwable) {
            return null;
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
