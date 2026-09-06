<?php

namespace App\Models;

use App\Enums\WatcherConditionType;
use App\Enums\WatcherCreatedBy;
use App\Enums\WatcherHealth;
use App\Enums\WatcherMode;
use App\Enums\WatcherReactionType;
use App\Enums\WatcherSourceType;
use App\Enums\WatcherStatus;
use App\Enums\WatcherTriggerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'public_id',
    'name',
    'status',
    'health',
    'mode',
    'trigger_type',
    'source_type',
    'source_config',
    'condition_type',
    'condition_config',
    'reaction_type',
    'reaction_config',
    'cooldown_seconds',
    'max_triggers_per_day',
    'aggregation_window_seconds',
    'last_checked_at',
    'last_triggered_at',
    'next_check_at',
    'cursor',
    'consecutive_failures',
    'last_error_category',
    'last_error',
    'blocked_notified_at',
    'created_by',
    'conversation_id',
    'project_id',
    'knowledge_entity_id',
    'task_id',
    'reminder_id',
    'integration_account_id',
])]
class Watcher extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WatcherStatus::class,
            'health' => WatcherHealth::class,
            'mode' => WatcherMode::class,
            'trigger_type' => WatcherTriggerType::class,
            'source_type' => WatcherSourceType::class,
            'condition_type' => WatcherConditionType::class,
            'reaction_type' => WatcherReactionType::class,
            'created_by' => WatcherCreatedBy::class,
            'source_config' => 'array',
            'condition_config' => 'array',
            'reaction_config' => 'array',
            'cursor' => 'array',
            'last_checked_at' => 'immutable_datetime',
            'last_triggered_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
            'blocked_notified_at' => 'immutable_datetime',
            'cooldown_seconds' => 'integer',
            'max_triggers_per_day' => 'integer',
            'aggregation_window_seconds' => 'integer',
            'consecutive_failures' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(WatcherOccurrence::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function knowledgeEntity(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntity::class, 'knowledge_entity_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    public function isActive(): bool
    {
        return $this->status === WatcherStatus::Active;
    }

    public function isExternal(): bool
    {
        return $this->trigger_type->isExternal();
    }

    public function baselineEstablished(): bool
    {
        return (bool) (($this->cursor ?? [])['baseline_established'] ?? false);
    }
}
