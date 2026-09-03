<?php

namespace App\Models;

use App\Enums\ConversationSummaryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'conversation_id',
    'summary',
    'from_message_id',
    'to_message_id',
    'message_count',
    'version',
    'status',
    'generated_by_provider',
    'generated_by_model',
    'generated_at',
    'metadata',
])]
class ConversationSummary extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationSummaryStatus::class,
            'generated_at' => 'immutable_datetime',
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
}
