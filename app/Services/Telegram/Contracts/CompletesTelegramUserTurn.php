<?php

namespace App\Services\Telegram\Contracts;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationTurnResult;

interface CompletesTelegramUserTurn
{
    public function complete(
        User $user,
        Conversation $conversation,
        string $text,
        ChannelContext $channel,
    ): ConversationTurnResult;
}
