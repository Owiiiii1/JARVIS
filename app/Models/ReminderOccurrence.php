<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reminder_id',
    'run_at',
    'status',
    'delivered_at',
    'completed_at',
    'delivery_snapshot',
])]
class ReminderOccurrence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_at' => 'immutable_datetime',
            'status' => ReminderStatus::class,
            'delivered_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'delivery_snapshot' => 'array',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
