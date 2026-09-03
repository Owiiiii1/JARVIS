<?php

namespace App\Models;

use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryAnalysisRunType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'conversation_id',
    'from_message_id',
    'to_message_id',
    'type',
    'status',
    'attempts',
    'provider',
    'model',
    'started_at',
    'completed_at',
    'last_error',
    'metadata',
])]
class MemoryAnalysisRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MemoryAnalysisRunType::class,
            'status' => MemoryAnalysisRunStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
