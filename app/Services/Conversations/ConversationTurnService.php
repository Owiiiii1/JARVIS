<?php

namespace App\Services\Conversations;

use App\Enums\ConversationKind;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatAttachments\ChatAttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Throwable;

final class ConversationTurnService
{
    public function __construct(
        private readonly MessagePersistenceService $messages,
        private readonly ConversationAiService $conversationAi,
        private readonly ChatAttachmentService $attachments,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function handleUserMessage(
        User $user,
        Conversation $conversation,
        string $text,
        ChannelContext $channel,
        array $files = [],
    ): ConversationTurnResult {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new AuthorizationException('Conversation is not owned by this user.');
        }

        if ($conversation->kind !== ConversationKind::Personal) {
            throw new InvalidArgumentException('Group conversations cannot enter personal Conversation AI.');
        }

        $body = trim($text);
        $files = array_values(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile,
        ));

        if ($body === '' && $files === []) {
            throw new InvalidArgumentException('Message body is empty.');
        }

        $pending = [];

        if ($files !== []) {
            $pending = $this->attachments->storePending($user, $files);
        }

        try {
            $inbound = $this->messages->persistInbound(new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::User,
                channel: $channel->channel,
                messageType: $files !== [] ? MessageType::Photo : MessageType::Text,
                body: $body === '' ? null : $body,
                channelMessageId: $channel->channelMessageId,
                occurredAt: $channel->occurredAt,
                metadata: $channel->metadata,
            ));

            if (! $inbound->created) {
                $this->attachments->discardPending($pending);

                return $this->completeExisting($inbound->message);
            }

            if ($pending !== []) {
                $this->attachments->linkToMessage($inbound->message, $pending);
                $pending = [];
            }
        } catch (Throwable $exception) {
            $this->attachments->discardPending($pending);

            if (isset($inbound) && $inbound->created) {
                try {
                    $inbound->message->delete();
                } catch (Throwable) {
                }
            }

            throw $exception;
        }

        $ai = $this->conversationAi->completeUserTurn($inbound->message->fresh(['attachments']) ?? $inbound->message);

        return new ConversationTurnResult(
            inbound: $inbound->message->fresh(['attachments']) ?? $inbound->message,
            created: true,
            assistantMessage: $ai->assistantMessage,
            errorText: $ai->errorText,
            skipped: $ai->skipped,
        );
    }

    private function completeExisting(Message $inbound): ConversationTurnResult
    {
        $existingAssistant = Message::query()
            ->where('parent_message_id', $inbound->id)
            ->where('role', MessageRole::Assistant)
            ->orderBy('id')
            ->first();

        if ($existingAssistant !== null) {
            return new ConversationTurnResult(
                inbound: $inbound->loadMissing('attachments'),
                created: false,
                assistantMessage: $existingAssistant,
                skipped: true,
            );
        }

        $ai = $this->conversationAi->completeUserTurn($inbound->loadMissing('attachments'));

        return new ConversationTurnResult(
            inbound: $inbound->fresh(['attachments']) ?? $inbound,
            created: false,
            assistantMessage: $ai->assistantMessage,
            errorText: $ai->errorText,
            skipped: $ai->skipped,
        );
    }
}
