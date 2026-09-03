<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserAiSetting;
use App\Services\Ai\DTO\AiChatMessage;

final class ConversationContextBuilder
{
    public const DEFAULT_RECENT_LIMIT = 30;

    public const MIN_RECENT_LIMIT = 5;

    public const MAX_RECENT_LIMIT = 40;

    /**
     * @return array{system_prompt: string, messages: list<AiChatMessage>}
     */
    public function build(
        User $user,
        Conversation $conversation,
        AiRoleSetting $configuration,
        ?Message $currentInbound = null,
        ?string $applicationEvent = null,
    ): array {
        $sections = [trim((string) $configuration->system_prompt)];

        $generalPrompt = $this->generalPromptFor($user);

        if ($generalPrompt !== null) {
            $sections[] = "User General Prompt:\n".$generalPrompt;
        }

        if (filled($applicationEvent)) {
            $sections[] = "Application event:\n".trim($applicationEvent);
        }

        $systemPrompt = trim(implode("\n\n", array_filter($sections)));
        $recent = $this->recentSemanticMessages($conversation, $configuration, $currentInbound);

        return [
            'system_prompt' => $systemPrompt,
            'messages' => $recent,
        ];
    }

    private function generalPromptFor(User $user): ?string
    {
        $prompt = UserAiSetting::query()
            ->where('user_id', $user->id)
            ->value('general_prompt');

        if (! is_string($prompt)) {
            return null;
        }

        $prompt = trim($prompt);

        return $prompt === '' ? null : $prompt;
    }

    /**
     * @return list<AiChatMessage>
     */
    private function recentSemanticMessages(
        Conversation $conversation,
        AiRoleSetting $configuration,
        ?Message $currentInbound,
    ): array {
        $limit = $this->recentLimit($configuration);

        $rows = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->get();

        $selected = [];

        foreach ($rows as $message) {
            if (! $this->isSemanticDialogue($message)) {
                continue;
            }

            $selected[] = $message;

            if (count($selected) >= $limit) {
                break;
            }
        }

        $selected = array_reverse($selected);

        if ($currentInbound !== null && $this->isSemanticDialogue($currentInbound)) {
            $alreadyIncluded = false;

            foreach ($selected as $message) {
                if ((int) $message->id === (int) $currentInbound->id) {
                    $alreadyIncluded = true;
                    break;
                }
            }

            if (! $alreadyIncluded) {
                $selected[] = $currentInbound;
            }
        }

        $payload = [];

        foreach ($selected as $message) {
            $role = $message->role === MessageRole::Assistant ? 'assistant' : 'user';
            $body = trim((string) $message->body);

            if ($body === '') {
                continue;
            }

            $payload[] = new AiChatMessage($role, $body);
        }

        return $payload;
    }

    public function isSemanticDialogue(Message $message): bool
    {
        if ($message->role === MessageRole::System) {
            return false;
        }

        if ($message->message_type === MessageType::System) {
            return false;
        }

        $metadata = $message->metadata ?? [];

        if (($metadata['technical'] ?? false) === true) {
            return false;
        }

        $body = (string) $message->body;

        if (str_contains($body, 'Сообщение сохранено')) {
            return false;
        }

        return in_array($message->role, [MessageRole::User, MessageRole::Assistant], true);
    }

    private function recentLimit(AiRoleSetting $configuration): int
    {
        $parameters = $configuration->parameters ?? [];
        $limit = (int) ($parameters['recent_message_limit'] ?? self::DEFAULT_RECENT_LIMIT);

        return max(self::MIN_RECENT_LIMIT, min(self::MAX_RECENT_LIMIT, $limit));
    }
}
