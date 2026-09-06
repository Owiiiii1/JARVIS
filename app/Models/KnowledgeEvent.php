<?php

namespace App\Models;

use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
    'type',
    'title',
    'occurred_at',
    'source_type',
    'source_fingerprint',
    'conversation_id',
    'confidence',
    'metadata',
])]
class KnowledgeEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => KnowledgeEventType::class,
            'source_type' => KnowledgeSourceType::class,
            'occurred_at' => 'immutable_datetime',
            'confidence' => 'float',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeEntity::class, 'knowledge_event_entities')
            ->withPivot(['role'])
            ->withTimestamps();
    }
}
