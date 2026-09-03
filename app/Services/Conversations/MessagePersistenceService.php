<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class MessagePersistenceService
{
    public function persistInbound(PersistMessageData $data): PersistMessageResult
    {
        return $this->persist($data, MessageRole::User);
    }

    public function persistOutbound(PersistMessageData $data): PersistMessageResult
    {
        return $this->persist($data, $data->role);
    }

    public function persistSystem(PersistMessageData $data): PersistMessageResult
    {
        return $this->persist($data, MessageRole::System);
    }

    private function persist(PersistMessageData $data, MessageRole $role): PersistMessageResult
    {
        $conversation = $data->conversation;
        $userId = (int) $conversation->user_id;

        if ($data->channelMessageId !== null) {
            $existing = $this->findByChannelMessage(
                $data->channel->value,
                (int) $conversation->id,
                $data->channelMessageId,
            );

            if ($existing !== null) {
                return new PersistMessageResult($existing, false);
            }
        }

        try {
            $message = DB::transaction(function () use ($data, $role, $conversation, $userId): Message {
                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'role' => $role,
                    'channel' => $data->channel,
                    'body' => $data->body,
                    'message_type' => $data->messageType,
                    'channel_message_id' => $data->channelMessageId,
                    'parent_message_id' => $data->parentMessageId,
                    'metadata' => $data->metadata,
                    'occurred_at' => $data->occurredAt ?? now(),
                ]);

                $conversation->forceFill([
                    'last_activity_at' => $message->occurred_at,
                ])->save();

                return $message;
            });
        } catch (QueryException $exception) {
            if ($data->channelMessageId !== null) {
                $existing = $this->findByChannelMessage(
                    $data->channel->value,
                    (int) $conversation->id,
                    $data->channelMessageId,
                );

                if ($existing !== null) {
                    return new PersistMessageResult($existing, false);
                }
            }

            throw $exception;
        }

        return new PersistMessageResult($message, true);
    }

    private function findByChannelMessage(string $channel, int $conversationId, string $channelMessageId): ?Message
    {
        return Message::query()
            ->where('channel', $channel)
            ->where('conversation_id', $conversationId)
            ->where('channel_message_id', $channelMessageId)
            ->first();
    }
}
