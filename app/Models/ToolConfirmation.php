<?php

namespace App\Models;

use App\Enums\ToolConfirmationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolConfirmation extends Model
{
    protected $hidden = [
        'arguments_encrypted',
    ];

    protected $fillable = [
        'public_id',
        'user_id',
        'conversation_id',
        'tool_name',
        'tool_call_id',
        'arguments_encrypted',
        'status',
        'expires_at',
        'confirmed_at',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ToolConfirmationStatus::class,
            'arguments_encrypted' => 'encrypted:array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['arguments_encrypted'], $array['arguments']);

        return $array;
    }

    public function isPending(): bool
    {
        return $this->status === ToolConfirmationStatus::Pending && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
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
