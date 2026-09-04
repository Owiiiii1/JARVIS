<?php

namespace App\Models;

use App\Enums\ToolConfirmationDecision;
use App\Enums\ToolExecutionLogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExecutionLog extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_id',
        'tool_name',
        'capability',
        'provider',
        'integration_account_id',
        'status',
        'confirmation_state',
        'duration_ms',
        'error_code',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ToolExecutionLogStatus::class,
            'confirmation_state' => ToolConfirmationDecision::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function integrationAccount(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class);
    }
}
