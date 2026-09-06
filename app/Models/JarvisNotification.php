<?php

namespace App\Models;

use App\Enums\JarvisNotificationSeverity;
use App\Enums\JarvisNotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'type',
    'title',
    'body',
    'severity',
    'source_type',
    'source_id',
    'dedupe_key',
    'action_url',
    'read_at',
    'dismissed_at',
    'occurred_at',
    'ai_phrased',
    'metadata',
])]
class JarvisNotification extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JarvisNotificationType::class,
            'severity' => JarvisNotificationSeverity::class,
            'read_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'ai_phrased' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null && $this->dismissed_at === null;
    }
}
