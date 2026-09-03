<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
    'name',
    'normalized_name',
    'description',
    'status',
    'metadata',
])]
class Project extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'project_conversations')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'project_topics')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function memories(): BelongsToMany
    {
        return $this->belongsToMany(Memory::class, 'project_memories')
            ->withPivot(['attached_at', 'metadata']);
    }

    public function telegramGroups(): BelongsToMany
    {
        return $this->belongsToMany(TelegramGroup::class, 'project_groups')
            ->withPivot(['attached_at', 'metadata']);
    }
}
