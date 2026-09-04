<?php

namespace App\Models;

use App\Enums\IntegrationAccountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationAccount extends Model
{
    protected $hidden = [
        'credentials_encrypted',
    ];

    protected $fillable = [
        'user_id',
        'provider',
        'external_account_id',
        'external_account_email',
        'status',
        'scopes',
        'credentials_encrypted',
        'metadata',
        'connected_at',
        'disconnected_at',
        'last_used_at',
        'last_success_at',
        'last_error_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationAccountStatus::class,
            'scopes' => 'array',
            'credentials_encrypted' => 'encrypted:array',
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['credentials_encrypted'], $array['credentials']);

        return $array;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ToolExecutionLog::class);
    }
}
