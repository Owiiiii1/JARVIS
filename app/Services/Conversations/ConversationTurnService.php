<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ConversationTurnService
{
    public function __construct(
        private readonly MessagePersistenceService $messages,
        private readonly ConversationAiService $conversationAi,
    ) {}

    public function handleUserMessage(
        User $user,
        Conversation $conversation,
        string $text,
        ChannelContext $channel,
    ): ConversationTurnResult {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new AuthorizationException('Conversation is not owned by this user.');
        }

        $body = trim($text);

        if ($body === '') {
            throw new InvalidArgumentException('Message body is empty.');
        }

        $inbound = $this->messages->persistInbound(new PersistMessageData(
            conversation: $conversation,
            role: MessageRole::User,
            channel: $channel->channel,
            messageType: MessageType::Text,
            body: $body,
            channelMessageId: $channel->channelMessageId,
            occurredAt: $channel->occurredAt,
            metadata: $channel->metadata,
        ));

        if (! $inbound->created) {
            $existingAssistant = Message::query()
                ->where('parent_message_id', $inbound->message->id)
                ->where('role', MessageRole::Assistant)
                ->orderBy('id')
                ->first();

            if ($existingAssistant !== null) {
                return new ConversationTurnResult(
                    inbound: $inbound->message,
                    created: false,
                    assistantMessage: $existingAssistant,
                    skipped: true,
                );
            }

            $ai = $this->conversationAi->completeUserTurn($inbound->message);

            return new ConversationTurnResult(
                inbound: $inbound->message->fresh(),
                created: false,
                assistantMessage: $ai->assistantMessage,
                errorText: $ai->errorText,
                skipped: $ai->skipped,
            );
        }

        $ai = $this->conversationAi->completeUserTurn($inbound->message);

        return new ConversationTurnResult(
            inbound: $inbound->message->fresh(),
            created: true,
            assistantMessage: $ai->assistantMessage,
            errorText: $ai->errorText,
            skipped: $ai->skipped,
        );
    }
}
