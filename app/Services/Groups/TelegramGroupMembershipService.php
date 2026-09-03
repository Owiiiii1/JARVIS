<?php

namespace App\Services\Groups;

use App\Enums\TelegramGroupStatus;
use App\Models\TelegramGroup;
use App\Services\Telegram\TelegramBotManager;
use SergiX44\Nutgram\Telegram\Properties\ChatMemberStatus;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\ChatMemberUpdated;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramGroupMembershipService
{
    public function __construct(
        private readonly TelegramGroupDiscoveryService $discovery,
        private readonly TelegramBotManager $telegram,
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

    /**
     * @return 'connected'|'restricted'|'left'|'unknown'
     */
    public function syncFromTelegram(TelegramGroup $group): string
    {
        $status = $this->telegram->botMembershipStatus((string) $group->telegram_chat_id);

        if ($status === 'unknown') {
            return $status;
        }

        $next = match ($status) {
            'left' => TelegramGroupStatus::Left,
            'restricted' => TelegramGroupStatus::Restricted,
            default => TelegramGroupStatus::Connected,
        };

        $group->forceFill([
            'status' => $next,
            'last_seen_at' => now(),
        ])->save();

        Log::info('telegram group membership', [
            'telegram_group_id' => $group->id,
            'update_type' => 'membership_sync',
            'status' => $next->value,
        ]);

        return $status;
    }

    public function archiveGroupsWhereBotHasLeft(): int
    {
        $archived = 0;

        TelegramGroup::query()->active()->orderBy('id')->each(function (TelegramGroup $group) use (&$archived): void {
            try {
                if ($this->syncFromTelegram($group) === 'left') {
                    $archived++;
                }
            } catch (Throwable $exception) {
                Log::warning('telegram group membership sync failed', [
                    'telegram_group_id' => $group->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        return $archived;
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
