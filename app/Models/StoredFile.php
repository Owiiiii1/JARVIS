<?php

namespace App\Models;

use App\Enums\StoredFileStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'public_id',
    'original_name',
    'display_name',
    'normalized_name',
    'mime_type',
    'extension',
    'size_bytes',
    'sha256',
    'storage_disk',
    'storage_path',
    'status',
    'extracted_chars',
    'chunk_count',
    'summary',
    'client_upload_id',
    'metadata',
    'uploaded_at',
    'processed_at',
    'deleted_at',
])]
class StoredFile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'extracted_chars' => 'integer',
            'chunk_count' => 'integer',
            'status' => StoredFileStatus::class,
            'metadata' => 'array',
            'uploaded_at' => 'datetime',
            'processed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(StoredFileChunk::class)->orderBy('chunk_index');
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'message_stored_files')
            ->withPivot('attached_at')
            ->withTimestamps();
    }

    public function isReady(): bool
    {
        return $this->status === StoredFileStatus::Ready && $this->deleted_at === null;
    }

    public function isDeleted(): bool
    {
        return $this->status === StoredFileStatus::Deleted || $this->deleted_at !== null;
    }
}
