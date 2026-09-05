<?php

namespace App\Services\Telegram;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationTurnResult;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Telegram\Contracts\CompletesTelegramUserTurn;

final class TelegramConversationTurnBridge implements CompletesTelegramUserTurn
{
    public function __construct(
        private readonly ConversationTurnService $turns,
    ) {}

    public function complete(
        User $user,
        Conversation $conversation,
        string $text,
        ChannelContext $channel,
    ): ConversationTurnResult {
        return $this->turns->handleUserMessage($user, $conversation, $text, $channel);
    }
}
