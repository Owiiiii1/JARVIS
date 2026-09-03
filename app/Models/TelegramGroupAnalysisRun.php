<?php

namespace App\Models;

use App\Enums\TelegramGroupAnalysisRunStatus;
use App\Enums\TelegramGroupAnalysisRunType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'telegram_group_id',
    'analysis_type',
    'from_at',
    'to_at',
    'status',
    'attempts',
    'provider',
    'model',
    'started_at',
    'completed_at',
    'last_error',
    'idempotency_key',
    'metadata',
])]
class TelegramGroupAnalysisRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analysis_type' => TelegramGroupAnalysisRunType::class,
            'status' => TelegramGroupAnalysisRunStatus::class,
            'from_at' => 'immutable_datetime',
            'to_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(TelegramGroupKnowledge::class, 'analysis_run_id');
    }

    public static function idempotencyKey(int $groupId, string $type, CarbonImmutable|string $fromAt, CarbonImmutable|string $toAt): string
    {
        $from = $fromAt instanceof CarbonImmutable ? (string) $fromAt->getTimestamp() : (string) CarbonImmutable::parse($fromAt)->getTimestamp();
        $to = $toAt instanceof CarbonImmutable ? (string) $toAt->getTimestamp() : (string) CarbonImmutable::parse($toAt)->getTimestamp();

        return hash('sha256', $groupId.'|'.$type.'|'.$from.'|'.$to);
    }
}
