<?php

namespace App\Services\Conversations;

use App\Enums\AiRoleKey;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\Exceptions\AiConfigurationException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ConversationAiService
{
    public const PAIRING_GREETING_EVENT = 'Пользователь только что подключил Jarvis. Поприветствуй его и коротко представься.';

    public const AI_FAILURE = 'Не удалось получить ответ от AI. Попробуйте ещё раз позже.';

    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly ConversationContextBuilder $contextBuilder,
        private readonly MessagePersistenceService $messages,
        private readonly AiChatGateway $gateway,
    ) {}

    public function completeUserTurn(Message $inbound): ConversationAiTurnResult
    {
        $inbound->loadMissing(['user', 'conversation']);

        $existing = $this->existingAssistantReply($inbound);

        if ($existing !== null) {
            return new ConversationAiTurnResult(skipped: true, assistantMessage: $existing);
        }

        $status = $this->processingStatus($inbound);

        if (in_array($status, ['completed', 'failed', 'pending'], true)) {
            return new ConversationAiTurnResult(skipped: true);
        }

        return $this->runTurn(
            user: $inbound->user ?? $inbound->conversation->user,
            conversation: $inbound->conversation,
            inbound: $inbound,
            applicationEvent: null,
        );
    }

    public function greetAfterPairing(User $user, Conversation $conversation): ConversationAiTurnResult
    {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Assistant)
            ->where('metadata->ai->event', 'pairing_greeting')
            ->first();

        if ($existing !== null) {
            return new ConversationAiTurnResult(skipped: true, assistantMessage: $existing);
        }

        return $this->runTurn(
            user: $user,
            conversation: $conversation,
            inbound: null,
            applicationEvent: self::PAIRING_GREETING_EVENT,
            eventName: 'pairing_greeting',
        );
    }

    private function runTurn(
        User $user,
        Conversation $conversation,
        ?Message $inbound,
        ?string $applicationEvent,
        ?string $eventName = null,
    ): ConversationAiTurnResult {
        $startedAt = microtime(true);
        $configuration = $this->resolver->resolveConversation($user);

        if ($inbound !== null) {
            $this->markInbound($inbound, 'pending');
        }

        try {
            $this->assertReady($configuration);

            $context = $this->contextBuilder->build(
                $user,
                $conversation,
                $configuration,
                $inbound,
                $applicationEvent,
            );

            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: $context['system_prompt'],
                messages: $context['messages'],
                parameters: is_array($configuration->parameters) ? $configuration->parameters : [],
            ));

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $roleKey = $configuration->roleKey()->value;

            $assistant = $this->messages->persistOutbound(new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::Assistant,
                channel: $inbound?->channel ?? MessageChannel::Telegram,
                messageType: MessageType::Text,
                body: $response->text,
                parentMessageId: $inbound?->id,
                occurredAt: now(),
                metadata: [
                    'ai' => [
                        'configuration' => $roleKey,
                        'provider' => $response->provider,
                        'model' => $response->model,
                        'latency_ms' => $latencyMs,
                        'prompt_tokens' => $response->inputTokens,
                        'completion_tokens' => $response->outputTokens,
                        'total_tokens' => $response->totalTokens,
                        'finish_reason' => $response->finishReason,
                        'event' => $eventName,
                    ],
                ],
            ))->message;

            if ($inbound !== null) {
                $this->markInbound($inbound, 'completed');
            }

            return new ConversationAiTurnResult(assistantMessage: $assistant);
        } catch (Throwable $exception) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::warning('AI conversation turn failed', [
                'provider' => $configuration->provider,
                'model' => $configuration->model,
                'configuration' => $configuration->roleKey()->value,
                'error_class' => $exception::class,
                'latency_ms' => $latencyMs,
            ]);

            $errorText = self::AI_FAILURE;

            $this->messages->persistSystem(new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::System,
                channel: $inbound?->channel ?? MessageChannel::Telegram,
                messageType: MessageType::System,
                body: $errorText,
                parentMessageId: $inbound?->id,
                occurredAt: now(),
                metadata: [
                    'technical' => true,
                    'ai' => [
                        'configuration' => $configuration->roleKey()->value,
                        'provider' => $configuration->provider,
                        'model' => $configuration->model,
                        'status' => 'failed',
                        'error_class' => $exception::class,
                        'latency_ms' => $latencyMs,
                        'event' => $eventName,
                    ],
                ],
            ));

            if ($inbound !== null) {
                $this->markInbound($inbound, 'failed');
            }

            return new ConversationAiTurnResult(errorText: $errorText);
        }
    }

    private function assertReady(AiRoleSetting $configuration): void
    {
        if ($configuration->roleKey() === AiRoleKey::OwnerAnalysis) {
            throw new AiConfigurationException('Owner Analysis AI is not used for personal conversations.');
        }

        if (! $configuration->is_enabled) {
            throw new AiConfigurationException('AI configuration is disabled.');
        }

        if (! filled($configuration->provider) || ! filled($configuration->model)) {
            throw new AiConfigurationException('AI configuration is incomplete.');
        }
    }

    private function existingAssistantReply(Message $inbound): ?Message
    {
        return Message::query()
            ->where('parent_message_id', $inbound->id)
            ->where('role', MessageRole::Assistant)
            ->orderBy('id')
            ->first();
    }

    private function processingStatus(Message $inbound): ?string
    {
        $status = $inbound->metadata['ai']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function markInbound(Message $inbound, string $status): void
    {
        $metadata = $inbound->metadata ?? [];
        $metadata['ai'] = array_merge($metadata['ai'] ?? [], [
            'status' => $status,
        ]);

        $inbound->forceFill(['metadata' => $metadata])->save();
    }
}
