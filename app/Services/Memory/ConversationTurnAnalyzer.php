<?php

namespace App\Services\Memory;

use App\Enums\MemoryScope;
use App\Enums\MemoryStatus;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Memory\DTO\MemoryAnalysisResult;
use App\Services\Memory\DTO\MemoryWriteStats;
use Illuminate\Support\Collection;

final class ConversationTurnAnalyzer
{
    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
        private readonly MemoryAnalysisPromptBuilder $prompts,
        private readonly MemoryAnalysisResultParser $parser,
        private readonly MemoryWriter $writer,
        private readonly ConversationContextBuilder $contextBuilder,
    ) {}

    /**
     * @return array{stats: MemoryWriteStats, provider: string, model: string}
     */
    public function analyze(User $user, Conversation $conversation, Message $from, Message $to): array
    {
        $messages = $this->contextMessages($conversation, $to);
        $allowedIds = $messages->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $result = $this->extract($user, $messages, $allowedIds);

        $stats = $this->writer->apply(
            user: $user,
            conversationId: (int) $conversation->id,
            result: $result,
            fallbackMessageIds: array_values(array_filter(
                $allowedIds,
                static fn (int $id): bool => $id >= (int) $from->id && $id <= (int) $to->id,
            )) ?: [(int) $from->id, (int) $to->id],
        );

        $configuration = $this->resolver->resolveAnalysis();

        return [
            'stats' => $stats,
            'provider' => (string) $configuration->provider,
            'model' => (string) $configuration->model,
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @param  list<int>  $allowedIds
     */
    public function extract(User $user, Collection $messages, array $allowedIds): MemoryAnalysisResult
    {
        $configuration = $this->resolver->resolveAnalysis();
        $existing = Memory::query()
            ->where('user_id', $user->id)
            ->where('scope', MemoryScope::Personal)
            ->where('status', MemoryStatus::Active)
            ->orderByDesc('last_confirmed_at')
            ->limit(20)
            ->get(['kind', 'normalized_key', 'content', 'status'])
            ->map(static fn (Memory $memory): array => [
                'kind' => $memory->kind->value,
                'key' => (string) $memory->normalized_key,
                'content' => $memory->content,
                'status' => $memory->status->value,
            ])
            ->all();

        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: (string) $configuration->system_prompt,
            messages: [
                new AiChatMessage('user', $this->prompts->build($messages, $existing)),
            ],
            parameters: is_array($configuration->parameters) ? $configuration->parameters : [],
        ));

        return $this->parser->parse($response->text, $allowedIds);
    }

    /**
     * @return Collection<int, Message>
     */
    private function contextMessages(Conversation $conversation, Message $to): Collection
    {
        $limit = (int) config('memory.analysis_context_messages');

        $rows = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<=', $to->id)
            ->orderByDesc('id')
            ->limit($limit * 2)
            ->get()
            ->reverse()
            ->values();

        return $rows
            ->filter(fn (Message $message): bool => $this->contextBuilder->isSemanticDialogue($message))
            ->take(-1 * $limit)
            ->values();
    }
}
