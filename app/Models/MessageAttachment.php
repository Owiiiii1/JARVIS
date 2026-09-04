<?php

namespace App\Models;

use App\Enums\AttachmentRetentionClass;
use App\Enums\AttachmentSummaryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'message_id',
    'user_id',
    'kind',
    'retention_class',
    'expires_at',
    'summary_status',
    'summary_text',
    'summarized_at',
    'purged_at',
    'purge_failure_count',
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
            'purge_failure_count' => 'integer',
            'metadata' => 'array',
            'retention_class' => AttachmentRetentionClass::class,
            'summary_status' => AttachmentSummaryStatus::class,
            'expires_at' => 'datetime',
            'summarized_at' => 'datetime',
            'purged_at' => 'datetime',
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

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function thumbnailPath(): ?string
    {
        $path = $this->metadata['thumbnail_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
