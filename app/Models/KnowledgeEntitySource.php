<?php

namespace App\Models;

use App\Enums\KnowledgeSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'knowledge_entity_id',
    'source_type',
    'source_fingerprint',
    'conversation_id',
    'message_id',
    'memory_id',
    'project_id',
    'task_id',
    'reminder_id',
    'stored_file_id',
    'confidence',
    'observed_at',
])]
class KnowledgeEntitySource extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => KnowledgeSourceType::class,
            'confidence' => 'float',
            'observed_at' => 'immutable_datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    public function hasLiveReference(): bool
    {
        return $this->conversation_id !== null
            || $this->message_id !== null
            || $this->memory_id !== null
            || $this->project_id !== null
            || $this->task_id !== null
            || $this->reminder_id !== null
            || $this->stored_file_id !== null
            || $this->source_type === KnowledgeSourceType::Manual;
    }
}
