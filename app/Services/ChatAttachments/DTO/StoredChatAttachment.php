<?php

namespace App\Services\ChatAttachments\DTO;

final readonly class StoredChatAttachment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $disk,
        public string $path,
        public ?string $thumbnailPath,
        public ?string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public ?int $width,
        public ?int $height,
        public ?string $sha256,
        public array $metadata = [],
    ) {}
}
