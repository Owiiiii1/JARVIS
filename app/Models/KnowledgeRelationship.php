<?php

namespace App\Models;

use App\Enums\KnowledgeRelationStatus;
use App\Enums\KnowledgeRelationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'source_entity_id',
    'target_entity_id',
    'type',
    'label',
    'status',
    'confidence',
    'valid_from',
    'valid_to',
    'superseded_at',
    'first_seen_at',
    'last_seen_at',
    'source_count',
    'metadata',
])]
class KnowledgeRelationship extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => KnowledgeRelationType::class,
            'status' => KnowledgeRelationStatus::class,
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntity::class, 'source_entity_id');
    }

    public function targetEntity(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntity::class, 'target_entity_id');
    }
}
