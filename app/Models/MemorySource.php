<?php

namespace App\Models;

use App\Enums\MemorySourceKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'memory_id',
    'message_id',
    'conversation_id',
    'summary_id',
    'source_kind',
])]
class MemorySource extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => MemorySourceKind::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $source): void {
            if ($source->created_at === null) {
                $source->created_at = now();
            }
        });
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(ConversationSummary::class, 'summary_id');
    }
}
