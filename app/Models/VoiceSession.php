<?php

namespace App\Models;

use App\Enums\VoiceOrigin;
use App\Enums\VoiceSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'user_id',
    'conversation_id',
    'origin',
    'status',
    'stt_provider',
    'tts_provider',
    'started_at',
    'last_activity_at',
    'ended_at',
    'error_code',
    'metadata',
])]
class VoiceSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => VoiceOrigin::class,
            'status' => VoiceSessionStatus::class,
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return is_array($this->metadata) ? $this->metadata : [];
    }
}
