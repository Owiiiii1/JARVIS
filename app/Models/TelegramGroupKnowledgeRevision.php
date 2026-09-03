<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'knowledge_id',
    'previous_content',
    'new_content',
    'previous_status',
    'new_status',
    'reason',
    'created_at',
])]
class TelegramGroupKnowledgeRevision extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(TelegramGroupKnowledge::class, 'knowledge_id');
    }
}
