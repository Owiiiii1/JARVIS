<?php

namespace App\Services\Telegram;

use App\Models\ChannelIdentity;

final class TelegramIdentityState
{
    public const AWAITING_NEW_CHAT_TITLE = 'new_chat_title';

    public function awaiting(ChannelIdentity $identity): ?string
    {
        $metadata = $identity->metadata ?? [];

        $value = $metadata['awaiting'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function isAwaitingNewChatTitle(ChannelIdentity $identity): bool
    {
        return $this->awaiting($identity) === self::AWAITING_NEW_CHAT_TITLE;
    }

    public function setAwaitingNewChatTitle(ChannelIdentity $identity): void
    {
        $this->setAwaiting($identity, self::AWAITING_NEW_CHAT_TITLE);
    }

    public function clear(ChannelIdentity $identity): void
    {
        $this->setAwaiting($identity, null);
    }

    private function setAwaiting(ChannelIdentity $identity, ?string $value): void
    {
        $metadata = $identity->metadata ?? [];

        if ($value === null) {
            unset($metadata['awaiting']);
        } else {
            $metadata['awaiting'] = $value;
        }

        $identity->forceFill([
            'metadata' => $metadata === [] ? null : $metadata,
        ])->save();
    }
}
