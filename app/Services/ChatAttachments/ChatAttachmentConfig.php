<?php

namespace App\Services\ChatAttachments;

final class ChatAttachmentConfig
{
    /**
     * Safe snapshot for Workspace / future Desktop/Mobile clients.
     *
     * @return array{
     *     max_images_per_message: int,
     *     max_file_size_mb: int,
     *     max_total_upload_mb: int,
     *     allowed_mime_types: list<string>,
     *     accept: string,
     *     thumbnail: array{max_width: int, max_height: int}
     * }
     */
    public static function publicLimits(): array
    {
        $types = config('chat_attachments.allowed_mime_types', []);

        return [
            'max_images_per_message' => self::maxImagesPerMessage(),
            'max_file_size_mb' => self::maxFileSizeMb(),
            'max_total_upload_mb' => self::maxTotalUploadMb(),
            'allowed_mime_types' => is_array($types)
                ? array_values(array_map(static fn ($type): string => (string) $type, $types))
                : [],
            'accept' => self::filePickerAccept(),
            'thumbnail' => [
                'max_width' => (int) config('chat_attachments.thumbnail.max_width', 320),
                'max_height' => (int) config('chat_attachments.thumbnail.max_height', 320),
            ],
        ];
    }

    public static function disk(): string
    {
        return (string) config('chat_attachments.disk', 'local');
    }

    public static function directory(): string
    {
        return trim((string) config('chat_attachments.directory', 'chat-attachments'), '/');
    }

    public static function maxImagesPerMessage(): int
    {
        return max(1, (int) config('chat_attachments.max_images_per_message', 5));
    }

    public static function maxFileSizeMb(): int
    {
        return max(1, (int) config('chat_attachments.max_file_size_mb', 10));
    }

    public static function maxTotalUploadMb(): int
    {
        return max(1, (int) config('chat_attachments.max_total_upload_mb', 25));
    }

    public static function maxFileSizeBytes(): int
    {
        return self::maxFileSizeMb() * 1024 * 1024;
    }

    public static function maxTotalUploadBytes(): int
    {
        return self::maxTotalUploadMb() * 1024 * 1024;
    }

    public static function maxFileSizeKilobytes(): int
    {
        return self::maxFileSizeMb() * 1024;
    }

    public static function maxPixels(): int
    {
        return max(1, (int) config('chat_attachments.max_pixels', 40_000_000));
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        $types = config('chat_attachments.allowed_mime_types', []);

        if (! is_array($types)) {
            return [];
        }

        return array_values(array_map(static fn ($type): string => (string) $type, $types));
    }

    public static function filePickerAccept(): string
    {
        $accept = self::allowedMimeTypes();

        foreach (self::allowedMimeTypes() as $mime) {
            $accept = array_merge($accept, match ($mime) {
                'image/jpeg' => ['.jpg', '.jpeg'],
                'image/png' => ['.png'],
                'image/webp' => ['.webp'],
                default => [],
            });
        }

        return implode(',', array_values(array_unique($accept)));
    }

    public static function thumbnailMaxWidth(): int
    {
        return max(32, (int) config('chat_attachments.thumbnail.max_width', 320));
    }

    public static function thumbnailMaxHeight(): int
    {
        return max(32, (int) config('chat_attachments.thumbnail.max_height', 320));
    }

    public static function thumbnailQuality(): int
    {
        return max(40, min(95, (int) config('chat_attachments.thumbnail.quality', 82)));
    }
}
