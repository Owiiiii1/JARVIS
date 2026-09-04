<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stored_file_id',
    'chunk_index',
    'content',
    'char_start',
    'char_end',
    'token_estimate',
    'metadata',
])]
class StoredFileChunk extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'token_estimate' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }
}
