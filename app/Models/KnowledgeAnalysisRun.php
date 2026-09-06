<?php

namespace App\Models;

use App\Enums\KnowledgeAnalysisRunStatus;
use App\Enums\KnowledgeSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'source_type',
    'source_fingerprint',
    'status',
    'attempts',
    'last_error',
    'started_at',
    'completed_at',
    'metadata',
])]
class KnowledgeAnalysisRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => KnowledgeSourceType::class,
            'status' => KnowledgeAnalysisRunStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
