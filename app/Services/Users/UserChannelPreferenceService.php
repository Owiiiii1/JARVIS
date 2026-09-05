<?php

namespace App\Services\Users;

use App\Enums\MessageChannel;
use App\Enums\TelegramResponseMode;
use App\Models\User;
use App\Models\UserChannelPreference;

final class UserChannelPreferenceService implements ResolvesTelegramResponseMode
{
    public function telegramResponseMode(User $user): TelegramResponseMode
    {
        $row = UserChannelPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', MessageChannel::Telegram->value)
            ->first();

        return $row?->response_mode ?? TelegramResponseMode::default();
    }

    public function setTelegramResponseMode(User $user, TelegramResponseMode $mode): UserChannelPreference
    {
        $row = UserChannelPreference::query()->firstOrNew([
            'user_id' => $user->id,
            'channel' => MessageChannel::Telegram->value,
        ]);

        $row->response_mode = $mode;
        $row->save();

        return $row;
    }
}
