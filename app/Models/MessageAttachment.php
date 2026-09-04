<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'message_id',
    'user_id',
    'kind',
    'storage_disk',
    'storage_path',
    'original_name',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'sha256',
    'metadata',
])]
class MessageAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE;
    }

    public function thumbnailPath(): ?string
    {
        $path = $this->metadata['thumbnail_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
