<?php

namespace App\Models;

use App\Enums\ScheduledReportPeriodMode;
use App\Enums\ScheduledReportScheduleKind;
use App\Enums\ScheduledReportStatus;
use App\Enums\ScheduledReportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'conversation_id',
    'name',
    'status',
    'timezone',
    'schedule_kind',
    'local_time',
    'days',
    'report_type',
    'period_mode',
    'sources',
    'delivery',
    'cursor',
    'last_run_at',
    'next_run_at',
    'last_success_at',
    'last_error_code',
    'created_by',
])]
class ScheduledReport extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'schedule_kind' => 'daily_local',
        'created_by' => 'tool',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScheduledReportStatus::class,
            'schedule_kind' => ScheduledReportScheduleKind::class,
            'report_type' => ScheduledReportType::class,
            'period_mode' => ScheduledReportPeriodMode::class,
            'days' => 'array',
            'sources' => 'array',
            'delivery' => 'array',
            'cursor' => 'array',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
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

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledReportRun::class);
    }

    public function isActive(): bool
    {
        return $this->status === ScheduledReportStatus::Active;
    }
}
