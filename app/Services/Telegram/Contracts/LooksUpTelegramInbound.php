<?php

namespace App\Services\Telegram\Contracts;

use App\Services\Telegram\DTO\TelegramExistingInbound;

interface LooksUpTelegramInbound
{
    public function find(int $conversationId, string $channelMessageId): ?TelegramExistingInbound;
}
