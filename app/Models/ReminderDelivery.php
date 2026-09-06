<?php

namespace App\Models;

use App\Enums\ReminderChannel;
use App\Enums\ReminderDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reminder_id',
    'channel',
    'status',
    'attempts',
    'delivered_at',
    'last_error',
    'next_retry_at',
])]
class ReminderDelivery extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ReminderChannel::class,
            'status' => ReminderDeliveryStatus::class,
            'attempts' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
