<?php

namespace App\Services\Memory;

use App\Enums\ConversationSummaryStatus;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Context\TokenEstimator;
use App\Services\Conversations\ConversationContextBuilder;
use Illuminate\Support\Collection;

final class ConversationSummaryService
{
    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
        private readonly SummaryPromptBuilder $prompts,
        private readonly ConversationContextBuilder $contextBuilder,
        private readonly TokenEstimator $estimator,
    ) {}

    public function semanticCountSince(?ConversationSummary $summary, Conversation $conversation): int
    {
        return $this->messagesAfter($conversation, $summary?->to_message_id)->count();
    }

    public function shouldUpdate(Conversation $conversation): bool
    {
        $current = $this->current($conversation);
        $messages = $this->messagesAfter($conversation, $current?->to_message_id);
        $threshold = (int) config('memory.summary_message_threshold');

        if ($messages->count() >= $threshold) {
            return true;
        }

        $joined = $messages
            ->map(static fn (Message $message): string => trim((string) $message->body))
            ->filter()
            ->implode("\n");

        return $this->estimator->estimateText($joined) >= (int) config('context_budget.summary_refresh_tokens', 2500);
    }

    public function current(Conversation $conversation): ?ConversationSummary
    {
        return ConversationSummary::query()
            ->where('user_id', $conversation->user_id)
            ->where('conversation_id', $conversation->id)
            ->where('status', ConversationSummaryStatus::Current)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array{summary: ConversationSummary, provider: string, model: string}|null
     */
    public function update(User $user, Conversation $conversation): ?array
    {
        $previous = $this->current($conversation);
        $messages = $this->messagesAfter($conversation, $previous?->to_message_id);

        if ($messages->isEmpty()) {
            return null;
        }

        $configuration = $this->resolver->resolveAnalysis();
        $previousText = $previous?->summary;
        $maxChars = max(500, (int) config('context_budget.summary_max_chars', 4000));

        if (is_string($previousText) && mb_strlen($previousText) > $maxChars) {
            $previousText = $this->summarize($this->prompts->reduce($conversation, [$previousText]));
        }

        $chunkSize = (int) config('memory.summary_initial_chunk');
        $text = $previous === null && $messages->count() > $chunkSize
            ? $this->chunkReduce($conversation, $messages, $chunkSize)
            : $this->summarize($this->prompts->incremental($previousText, $conversation, $messages));

        if ($previous !== null) {
            $previous->forceFill(['status' => ConversationSummaryStatus::Superseded])->save();
        }

        $summary = ConversationSummary::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'summary' => $text,
            'from_message_id' => $previous?->from_message_id ?? $messages->first()?->id,
            'to_message_id' => $messages->last()?->id,
            'message_count' => ($previous?->message_count ?? 0) + $messages->count(),
            'version' => ($previous?->version ?? 0) + 1,
            'status' => ConversationSummaryStatus::Current,
            'generated_by_provider' => $configuration->provider,
            'generated_by_model' => $configuration->model,
            'generated_at' => now(),
        ]);

        return [
            'summary' => $summary,
            'provider' => (string) $configuration->provider,
            'model' => (string) $configuration->model,
        ];
    }

    /**
     * @return Collection<int, Message>
     */
    private function messagesAfter(Conversation $conversation, ?int $afterId): Collection
    {
        $query = Message::query()->where('conversation_id', $conversation->id);

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $cap = max(20, (int) config('context_budget.unsummarized_message_cap', 80));

        return $query
            ->orderBy('id')
            ->limit($cap * 3)
            ->get()
            ->filter(fn (Message $message): bool => $this->contextBuilder->isSemanticDialogue($message))
            ->take($cap)
            ->values();
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function chunkReduce(Conversation $conversation, Collection $messages, int $chunkSize): string
    {
        $chunks = $messages->chunk($chunkSize);
        $partials = [];

        foreach ($chunks as $chunk) {
            $partials[] = $this->summarize($this->prompts->incremental(null, $conversation, $chunk));
        }

        if (count($partials) === 1) {
            return $partials[0];
        }

        return $this->summarize($this->prompts->reduce($conversation, $partials));
    }

    private function summarize(string $prompt): string
    {
        $configuration = $this->resolver->resolveAnalysis();
        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: (string) $configuration->system_prompt,
            messages: [new AiChatMessage('user', $prompt)],
            parameters: is_array($configuration->parameters) ? $configuration->parameters : [],
        ));

        $payload = StructuredJsonParser::objectFromText($response->text);
        $summary = trim((string) ($payload['summary'] ?? ''));

        if ($summary === '') {
            $summary = trim($response->text);
        }

        return mb_substr($summary, 0, max(500, (int) config('context_budget.summary_max_chars', 4000)));
    }
}
