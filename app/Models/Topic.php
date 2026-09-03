<?php

namespace App\Models;

use App\Enums\TopicStatus;
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
    'first_seen_at',
    'last_seen_at',
    'metadata',
])]
class Topic extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TopicStatus::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'message_topic_relations')
            ->withPivot(['confidence', 'source'])
            ->withTimestamps();
    }
}
