<?php

namespace App\Services\ChatAttachments;

use App\Enums\AttachmentRetentionClass;
use App\Enums\AttachmentSummaryStatus;
use App\Jobs\SummarizeMessageAttachmentJob;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\ChatAttachments\DTO\StoredChatAttachment;
use App\Services\ChatAttachments\Exceptions\ChatAttachmentException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ChatAttachmentService
{
    public function __construct(
        private readonly ChatAttachmentInspector $inspector,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<StoredChatAttachment>
     */
    public function storePending(User $user, array $files): array
    {
        $files = array_values(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile,
        ));

        if ($files === []) {
            return [];
        }

        if (count($files) > ChatAttachmentConfig::maxImagesPerMessage()) {
            throw new ChatAttachmentException(
                'too_many_images',
                'Можно прикрепить не больше '.ChatAttachmentConfig::maxImagesPerMessage().' изображений.',
            );
        }

        $total = 0;

        foreach ($files as $file) {
            $total += max(0, (int) $file->getSize());
        }

        if ($total > ChatAttachmentConfig::maxTotalUploadBytes()) {
            throw new ChatAttachmentException(
                'total_too_large',
                'Суммарный размер вложений больше '.ChatAttachmentConfig::maxTotalUploadMb().' МБ.',
            );
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->storeOne($user, $file);
            }
        } catch (Throwable $exception) {
            $this->discardPending($stored);

            throw $exception;
        }

        return $stored;
    }

    /**
     * @param  list<StoredChatAttachment>  $stored
     */
    public function linkToMessage(Message $message, array $stored): void
    {
        foreach ($stored as $item) {
            $attachment = MessageAttachment::query()->create([
                'message_id' => $message->id,
                'user_id' => (int) $message->user_id,
                'kind' => MessageAttachment::KIND_IMAGE,
                'retention_class' => ChatAttachmentConfig::defaultRetentionClass(),
                'expires_at' => now()->addHours(ChatAttachmentConfig::retentionHours()),
                'summary_status' => AttachmentSummaryStatus::Pending,
                'storage_disk' => $item->disk,
                'storage_path' => $item->path,
                'original_name' => $item->originalName,
                'mime_type' => $item->mimeType,
                'size_bytes' => $item->sizeBytes,
                'width' => $item->width,
                'height' => $item->height,
                'sha256' => $item->sha256,
                'metadata' => $item->metadata,
            ]);

            if ($attachment->retention_class === AttachmentRetentionClass::Ephemeral) {
                SummarizeMessageAttachmentJob::dispatch((int) $attachment->id)
                    ->onQueue(ChatAttachmentConfig::summaryQueue());
            }
        }

        if ($stored !== []) {
            try {
                Log::info('chat attachments stored', [
                    'message_id' => $message->id,
                    'user_id' => (int) $message->user_id,
                    'count' => count($stored),
                    'attachments' => array_map(static fn (StoredChatAttachment $item): array => [
                        'mime' => $item->mimeType,
                        'size_bytes' => $item->sizeBytes,
                    ], $stored),
                ]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  list<StoredChatAttachment>  $stored
     */
    public function discardPending(array $stored): void
    {
        foreach ($stored as $item) {
            $this->deleteQuietly($item->disk, $item->path);

            if ($item->thumbnailPath !== null) {
                $this->deleteQuietly($item->disk, $item->thumbnailPath);
            }
        }
    }

    private function storeOne(User $user, UploadedFile $file): StoredChatAttachment
    {
        $inspected = $this->inspector->inspect($file);
        $disk = ChatAttachmentConfig::disk();
        $extension = $this->extensionForMime($inspected['mime']);
        $directory = ChatAttachmentConfig::directory().'/'.$user->id.'/'.now()->format('Y/m');
        $basename = (string) Str::uuid();
        $path = $directory.'/'.$basename.'.'.$extension;

        $written = Storage::disk($disk)->put($path, $inspected['bytes']);

        if ($written === false) {
            throw new ChatAttachmentException('storage_failed', 'Не удалось сохранить изображение.');
        }

        $thumbnailPath = $this->writeThumbnail($disk, $directory, $basename, $inspected['bytes'], $inspected['width'], $inspected['height']);
        $sha256 = hash('sha256', $inspected['bytes']);

        return new StoredChatAttachment(
            disk: $disk,
            path: $path,
            thumbnailPath: $thumbnailPath,
            originalName: $this->sanitizeOriginalName($file->getClientOriginalName()),
            mimeType: $inspected['mime'],
            sizeBytes: strlen($inspected['bytes']),
            width: $inspected['width'],
            height: $inspected['height'],
            sha256: $sha256,
            metadata: array_filter([
                'thumbnail_path' => $thumbnailPath,
            ]),
        );
    }

    private function writeThumbnail(
        string $disk,
        string $directory,
        string $basename,
        string $bytes,
        int $width,
        int $height,
    ): ?string {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $maxW = ChatAttachmentConfig::thumbnailMaxWidth();
        $maxH = ChatAttachmentConfig::thumbnailMaxHeight();
        $scale = min($maxW / max(1, $width), $maxH / max(1, $height), 1.0);
        $thumbW = max(1, (int) round($width * $scale));
        $thumbH = max(1, (int) round($height * $scale));
        $thumb = imagecreatetruecolor($thumbW, $thumbH);

        if ($thumb === false) {
            imagedestroy($source);

            return null;
        }

        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbW, $thumbH, $width, $height);

        ob_start();
        imagejpeg($thumb, null, ChatAttachmentConfig::thumbnailQuality());
        $encoded = ob_get_clean();

        imagedestroy($thumb);
        imagedestroy($source);

        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $path = $directory.'/'.$basename.'_thumb.jpg';

        if (Storage::disk($disk)->put($path, $encoded) === false) {
            return null;
        }

        return $path;
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    private function sanitizeOriginalName(?string $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $base) ?? '';
        $base = trim($base, '._ ');

        if ($base === '') {
            return null;
        }

        return Str::limit($base, 120, '');
    }

    private function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
        }
    }
}
