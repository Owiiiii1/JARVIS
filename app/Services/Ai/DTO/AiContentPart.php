<?php

namespace App\Services\Ai\DTO;

final readonly class AiContentPart
{
    public const TEXT = 'text';

    public const IMAGE = 'image';

    private function __construct(
        public string $type,
        public ?string $text = null,
        public ?string $mimeType = null,
        public ?string $data = null,
        public ?int $attachmentId = null,
        public ?int $sizeBytes = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(type: self::TEXT, text: $text);
    }

    public static function image(
        string $mimeType,
        string $base64,
        ?int $attachmentId = null,
        ?int $sizeBytes = null,
    ): self {
        return new self(
            type: self::IMAGE,
            mimeType: $mimeType,
            data: $base64,
            attachmentId: $attachmentId,
            sizeBytes: $sizeBytes,
        );
    }

    public function isText(): bool
    {
        return $this->type === self::TEXT;
    }

    public function isImage(): bool
    {
        return $this->type === self::IMAGE;
    }
}
