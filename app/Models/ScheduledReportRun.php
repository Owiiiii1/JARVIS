<?php

namespace App\Models;

use App\Enums\ScheduledReportRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'scheduled_report_id',
    'user_id',
    'slot_key',
    'status',
    'collected',
    'body',
    'notification_id',
    'error_code',
    'source_errors',
    'started_at',
    'finished_at',
])]
class ScheduledReportRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScheduledReportRunStatus::class,
            'collected' => 'array',
            'source_errors' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ScheduledReport::class, 'scheduled_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
