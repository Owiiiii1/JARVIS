<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBotSetting extends Model
{
    protected $fillable = [
        'bot_token',
        'bot_username',
        'webhook_url',
        'webhook_secret',
        'is_webhook_set',
        'is_connected',
        'last_checked_at',
        'last_webhook_set_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_webhook_set' => 'boolean',
            'is_connected' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_webhook_set_at' => 'datetime',
        ];
    }
}
