<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'parent_task_id',
    'title',
    'description',
    'status',
    'priority',
    'due_at',
    'timezone',
    'source_conversation_id',
    'source_message_id',
    'project_id',
    'calendar_provider',
    'calendar_id',
    'calendar_event_id',
    'completed_at',
    'cancelled_at',
    'metadata',
])]
class Task extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'priority' => 'normal',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [TaskStatus::Open, TaskStatus::InProgress], true);
    }
}
