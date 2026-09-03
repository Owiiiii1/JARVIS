<?php

namespace App\Services\Groups;

use App\Enums\TelegramGroupStatus;
use App\Models\TelegramGroup;
use SergiX44\Nutgram\Telegram\Properties\ChatMemberStatus;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberUpdated;
use Illuminate\Support\Facades\Log;

final class TelegramGroupMembershipService
{
    public function __construct(
        private readonly TelegramGroupDiscoveryService $discovery,
    ) {}

    public function handleMyChatMember(ChatMemberUpdated $update): void
    {
        $chat = $update->chat;
        $type = is_string($chat->type) ? $chat->type : $chat->type->value;

        if (! in_array($type, [ChatType::GROUP->value, ChatType::SUPERGROUP->value], true)) {
            return;
        }

        $group = $this->discovery->discoverOrCreate((string) $chat->id, [
            'title' => $chat->title ?? null,
            'username' => $chat->username ?? null,
            'chat_type' => $type,
            'is_forum' => (bool) ($chat->is_forum ?? false),
        ]);

        $status = $update->new_chat_member->status;
        $value = $status instanceof ChatMemberStatus ? $status->value : (string) $status;
        $next = $this->mapStatus($value);

        $group->forceFill([
            'status' => $next,
            'last_seen_at' => now(),
        ])->save();

        Log::info('telegram group membership', [
            'telegram_group_id' => $group->id,
            'update_type' => 'my_chat_member',
            'status' => $next->value,
        ]);
    }

    private function mapStatus(string $telegramStatus): TelegramGroupStatus
    {
        return match ($telegramStatus) {
            ChatMemberStatus::LEFT->value, ChatMemberStatus::KICKED->value => TelegramGroupStatus::Left,
            ChatMemberStatus::RESTRICTED->value => TelegramGroupStatus::Restricted,
            default => TelegramGroupStatus::Connected,
        };
    }
}
