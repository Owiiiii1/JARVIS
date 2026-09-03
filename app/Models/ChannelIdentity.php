<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'channel',
    'external_user_id',
    'external_chat_id',
    'username',
    'first_name',
    'last_name',
    'linked_at',
    'last_seen_at',
    'active_conversation_id',
    'metadata',
])]
class ChannelIdentity extends Model
{
    public const CHANNEL_TELEGRAM = 'telegram';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activeConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'active_conversation_id');
    }

    public static function findTelegramByExternalUserId(string $externalUserId): ?self
    {
        return self::query()
            ->where('channel', self::CHANNEL_TELEGRAM)
            ->where('external_user_id', $externalUserId)
            ->first();
    }

    public static function findTelegramForUser(int $userId): ?self
    {
        return self::query()
            ->where('channel', self::CHANNEL_TELEGRAM)
            ->where('user_id', $userId)
            ->first();
    }
}
