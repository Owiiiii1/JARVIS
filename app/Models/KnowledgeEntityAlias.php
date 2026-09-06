<?php

namespace App\Models;

use App\Enums\KnowledgeSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'knowledge_entity_id',
    'alias',
    'normalized_alias',
    'source_type',
    'confidence',
])]
class KnowledgeEntityAlias extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => KnowledgeSourceType::class,
            'confidence' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntity::class, 'knowledge_entity_id');
    }
}
