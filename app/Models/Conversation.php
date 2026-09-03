<?php

namespace App\Models;

use App\Enums\ConversationKind;
use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'kind', 'title', 'status', 'last_activity_at'])]
class Conversation extends Model
{
    public const DEFAULT_TITLE = 'Основной';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'status' => ConversationStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function summaries(): HasMany
    {
        return $this->hasMany(ConversationSummary::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_conversations')
            ->withPivot(['attached_at', 'metadata']);
    }
}
