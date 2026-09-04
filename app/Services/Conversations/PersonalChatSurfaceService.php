<?php

namespace App\Services\Conversations;

use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatAttachments\Exceptions\ChatAttachmentException;
use App\Services\Storage\Exceptions\StoredFileException;
use App\Services\Tools\ToolConfirmationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PersonalChatSurfaceService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationTurnService $turns,
        private readonly MessageHistoryService $history,
        private readonly ToolConfirmationService $confirmations,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sidebar(User $user, ?int $currentId = null, int $limit = ConversationService::CABINET_LIST_LIMIT): array
    {
        return $this->conversations->listForUser($user, $limit)
            ->map(static fn ($conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                'current' => $currentId !== null && (int) $conversation->id === $currentId,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function userProfile(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'timezone' => $user->timezone,
        ];
    }

    public function ensureOwned(User $user, int $conversationId): Conversation
    {
        return $this->conversations->ensureOwned($user, $conversationId);
    }

    public function latestOrDefault(User $user): Conversation
    {
        return $this->conversations->latestOrDefault($user);
    }

    public function createChat(User $user): Conversation
    {
        return $this->conversations->createPersonal($user, ConversationService::NEW_CHAT_TITLE);
    }

    public function rename(User $user, Conversation $conversation, string $title): Conversation
    {
        return $this->conversations->rename($user, $conversation, $title);
    }

    /**
     * @return array{messages: list<array<string, mixed>>, has_more: bool, oldest_id: int|null}
     */
    public function page(Conversation $conversation, ?int $beforeId = null, ?int $limit = null): array
    {
        return $this->history->page(
            $conversation,
            $beforeId,
            $limit ?? MessageHistoryService::PAGE_SIZE,
        );
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<UploadedFile>  $documents
     * @return array<string, mixed>
     */
    public function sendTurn(
        User $user,
        Conversation $conversation,
        string $body,
        string $clientMessageId,
        array $files = [],
        array $documents = [],
    ): array {
        try {
            $turn = $this->turns->handleUserMessage(
                $user,
                $conversation,
                $body,
                new ChannelContext(
                    channel: MessageChannel::Web,
                    channelMessageId: $clientMessageId,
                ),
                $files,
                $documents,
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (ChatAttachmentException $exception) {
            throw ValidationException::withMessages([
                'images' => $exception->getMessage(),
            ]);
        } catch (StoredFileException $exception) {
            throw ValidationException::withMessages([
                'files' => $exception->getMessage(),
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'body' => $exception->getMessage(),
            ]);
        }

        return $this->turnPayload($turn, $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConfirmation(
        User $user,
        string $publicId,
        bool $confirm,
        string $clientMessageId,
    ): array {
        $row = $this->confirmations->findOwnedByPublicId($user, $publicId);

        if ($row === null) {
            abort(404);
        }

        $conversation = $this->conversations->ensureOwned($user, (int) $row->conversation_id);
        $pending = $this->confirmations->findOwnedPending($user, $conversation, $publicId);

        if ($pending === null || ! $pending->isPending()) {
            abort(404);
        }

        $latest = $this->confirmations->latestPending($user, $conversation);

        if ($latest === null || $latest->public_id !== $pending->public_id) {
            abort(409, 'This confirmation is no longer the active pending action.');
        }

        return $this->sendTurn(
            $user,
            $conversation,
            $confirm ? 'да' : 'отмена',
            $clientMessageId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function turnPayload(ConversationTurnResult $turn, Conversation $conversation): array
    {
        $fresh = $conversation->fresh() ?? $conversation;

        return [
            'inbound' => $this->history->toArray($turn->inbound),
            'assistant' => $turn->assistantMessage !== null
                ? $this->history->toArray($turn->assistantMessage)
                : null,
            'error' => $turn->errorText,
            'duplicate' => ! $turn->created,
            'conversation' => [
                'id' => $fresh->id,
                'title' => $fresh->title,
                'last_activity_at' => optional($fresh->last_activity_at)?->toIso8601String(),
            ],
        ];
    }
}
