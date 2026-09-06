<?php

namespace App\Models;

use App\Enums\WatcherOccurrenceStatus;
use App\Enums\WatcherReactionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'watcher_id',
    'user_id',
    'trigger_fingerprint',
    'detected_at',
    'status',
    'matched_condition',
    'reaction_status',
    'notification_id',
    'knowledge_event_id',
    'error_category',
    'metadata',
])]
class WatcherOccurrence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WatcherOccurrenceStatus::class,
            'reaction_status' => WatcherReactionStatus::class,
            'detected_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function watcher(): BelongsTo
    {
        return $this->belongsTo(Watcher::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(JarvisNotification::class, 'notification_id');
    }
}
