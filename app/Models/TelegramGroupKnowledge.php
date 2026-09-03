<?php

namespace App\Models;

use App\Enums\TelegramGroupKnowledgeStatus;
use App\Enums\TelegramGroupKnowledgeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'telegram_group_id',
    'analysis_run_id',
    'type',
    'title',
    'content',
    'structured_data',
    'confidence',
    'status',
    'normalized_key',
    'valid_from',
    'valid_until',
    'source_from_message_id',
    'source_to_message_id',
    'supersedes_id',
    'generated_by_provider',
    'generated_by_model',
    'generated_at',
    'metadata',
])]
class TelegramGroupKnowledge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TelegramGroupKnowledgeType::class,
            'status' => TelegramGroupKnowledgeStatus::class,
            'structured_data' => 'array',
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(TelegramGroupAnalysisRun::class, 'analysis_run_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(TelegramGroupKnowledgeSource::class, 'knowledge_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TelegramGroupKnowledgeRevision::class, 'knowledge_id');
    }

    public function supersededItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
