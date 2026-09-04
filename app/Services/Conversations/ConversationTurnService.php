<?php

namespace App\Services\Conversations;

use App\Enums\ConversationKind;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatAttachments\ChatAttachmentService;
use App\Services\Storage\StoredFileService;
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
        private readonly StoredFileService $storedFiles,
    ) {}

    /**
     * @param  list<UploadedFile>  $files  Chat images (ephemeral).
     * @param  list<UploadedFile>  $documents  Persistent Storage text files.
     */
    public function handleUserMessage(
        User $user,
        Conversation $conversation,
        string $text,
        ChannelContext $channel,
        array $files = [],
        array $documents = [],
    ): ConversationTurnResult {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new AuthorizationException('Conversation is not owned by this user.');
        }

        if (! $user->isActive()) {
            throw new AuthorizationException('Account is not active.');
        }

        if ($conversation->kind !== ConversationKind::Personal) {
            throw new InvalidArgumentException('Group conversations cannot enter personal Conversation AI.');
        }

        $body = trim($text);
        $files = array_values(array_filter(
            $files,
            static fn ($file): bool => $file instanceof UploadedFile,
        ));
        $documents = array_values(array_filter(
            $documents,
            static fn ($file): bool => $file instanceof UploadedFile,
        ));

        if ($body === '' && $files === [] && $documents === []) {
            throw new InvalidArgumentException('Message body is empty.');
        }

        $pending = [];

        if ($files !== []) {
            $pending = $this->attachments->storePending($user, $files);
        }

        try {
            $type = MessageType::Text;

            if ($files !== []) {
                $type = MessageType::Photo;
            } elseif ($documents !== []) {
                $type = MessageType::Document;
            }

            $inbound = $this->messages->persistInbound(new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::User,
                channel: $channel->channel,
                messageType: $type,
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

            if ($documents !== []) {
                $this->storedFiles->upload($user, $documents, null, $inbound->message);
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

        $fresh = $inbound->message->fresh(['attachments', 'storedFiles']) ?? $inbound->message;
        $ai = $this->conversationAi->completeUserTurn($fresh);

        return new ConversationTurnResult(
            inbound: $fresh->fresh(['attachments', 'storedFiles']) ?? $fresh,
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
                inbound: $inbound->loadMissing(['attachments', 'storedFiles']),
                created: false,
                assistantMessage: $existingAssistant,
                skipped: true,
            );
        }

        $ai = $this->conversationAi->completeUserTurn($inbound->loadMissing(['attachments', 'storedFiles']));

        return new ConversationTurnResult(
            inbound: $inbound->fresh(['attachments', 'storedFiles']) ?? $inbound,
            created: false,
            assistantMessage: $ai->assistantMessage,
            errorText: $ai->errorText,
            skipped: $ai->skipped,
        );
    }
}
