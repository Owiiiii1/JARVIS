<?php

namespace App\Services\Storage;

final class StoredFileConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function publicLimits(): array
    {
        return [
            'max_file_size_mb' => self::maxFileSizeMb(),
            'max_files_per_upload' => self::maxFilesPerUpload(),
            'max_total_upload_mb' => self::maxTotalUploadMb(),
            'allowed_extensions' => self::allowedExtensions(),
            'accept' => self::filePickerAccept(),
            'direct_preview_chars' => self::directPreviewChars(),
        ];
    }

    public static function disk(): string
    {
        return (string) config('jarvis_storage.disk', 'local');
    }

    public static function directory(): string
    {
        return trim((string) config('jarvis_storage.directory', 'jarvis-storage'), '/');
    }

    public static function queue(): string
    {
        return (string) config('jarvis_storage.queue', 'default');
    }

    public static function maxFileSizeMb(): int
    {
        return max(1, (int) config('jarvis_storage.max_file_size_mb', 20));
    }

    public static function maxFileSizeBytes(): int
    {
        return self::maxFileSizeMb() * 1024 * 1024;
    }

    public static function maxFileSizeKilobytes(): int
    {
        return self::maxFileSizeMb() * 1024;
    }

    public static function maxFilesPerUpload(): int
    {
        return max(1, (int) config('jarvis_storage.max_files_per_upload', 8));
    }

    public static function maxTotalUploadMb(): int
    {
        return max(1, (int) config('jarvis_storage.max_total_upload_mb', 40));
    }

    public static function maxTotalUploadBytes(): int
    {
        return self::maxTotalUploadMb() * 1024 * 1024;
    }

    public static function maxExtractedChars(): int
    {
        return max(1000, (int) config('jarvis_storage.max_extracted_chars_per_file', 2_000_000));
    }

    public static function chunkChars(): int
    {
        return max(1000, (int) config('jarvis_storage.chunk_chars', 8000));
    }

    public static function chunkOverlapChars(): int
    {
        return max(0, min(self::chunkChars() - 1, (int) config('jarvis_storage.chunk_overlap_chars', 400)));
    }

    public static function inlineTurnChars(): int
    {
        return max(200, (int) config('jarvis_storage.inline_turn_chars', 4000));
    }

    public static function directPreviewChars(): int
    {
        return max(200, (int) config('jarvis_storage.direct_preview_chars', 8000));
    }

    public static function searchResultLimit(): int
    {
        return max(1, min(20, (int) config('jarvis_storage.search_result_limit', 8)));
    }

    public static function maxChunksPerToolResult(): int
    {
        return max(1, min(10, (int) config('jarvis_storage.max_chunks_per_tool_result', 4)));
    }

    public static function maxToolChars(): int
    {
        return max(500, (int) config('jarvis_storage.max_tool_chars', 6000));
    }

    public static function maxExcerptChars(): int
    {
        return max(120, (int) config('jarvis_storage.max_excerpt_chars', 1200));
    }

    public static function listPageSize(): int
    {
        return max(10, min(100, (int) config('jarvis_storage.list_page_size', 30)));
    }

    public static function syncProcessMaxBytes(): int
    {
        return max(1024, (int) config('jarvis_storage.sync_process_max_bytes', 524288));
    }

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        $items = config('jarvis_storage.allowed_extensions', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => strtolower((string) $item), $items));
    }

    public static function filePickerAccept(): string
    {
        return implode(',', array_map(
            static fn (string $extension): string => '.'.$extension,
            self::allowedExtensions(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        $items = config('jarvis_storage.allowed_mime_types', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => strtolower((string) $item), $items));
    }
}
