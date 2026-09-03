<?php

namespace App\Services\Groups;

use App\Models\AiRoleSetting;
use App\Models\Message;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Groups\DTO\GroupAnalysisResult;
use Illuminate\Support\Facades\Log;

final class GroupAnalysisService
{
    public function __construct(
        private readonly GroupTimeRangeService $ranges,
        private readonly GroupAnalysisTranscriptFormatter $formatter,
        private readonly GroupMessageChunker $chunker,
        private readonly GroupAnalysisPromptBuilder $prompts,
        private readonly GroupAnalysisResultParser $parser,
        private readonly GroupKnowledgeWriter $writer,
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function process(TelegramGroupAnalysisRun $run): array
    {
        $group = $run->group()->firstOrFail();
        $timezone = $this->ranges->timezone($group);
        $started = microtime(true);

        $messages = Message::query()
            ->where('telegram_group_id', $group->id)
            ->where('occurred_at', '>=', $run->from_at)
            ->where('occurred_at', '<', $run->to_at)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            $metadata = array_merge($run->metadata ?? [], [
                'no_data' => true,
                'chunk_count' => 0,
                'reduce_used' => false,
                'summaries' => 0,
                'decisions' => 0,
                'tasks' => 0,
                'events' => 0,
            ]);

            Log::info('telegram group analysis completed', [
                'run_id' => $run->id,
                'telegram_group_id' => $group->id,
                'from_at' => $run->from_at?->toIso8601String(),
                'to_at' => $run->to_at?->toIso8601String(),
                'chunk_count' => 0,
                'no_data' => true,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return [
                'provider' => null,
                'model' => null,
                'metadata' => $metadata,
            ];
        }

        $lines = $this->formatter->lines($group, $messages, $timezone);
        $chunks = $this->chunker->chunk($lines);
        $allowedIds = $messages->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $configuration = $this->resolver->resolveAnalysis();
        $chunkResults = [];

        foreach ($chunks as $chunk) {
            $prompt = $this->prompts->chunk(array_column($chunk, 'line'));
            $chunkAllowed = array_column($chunk, 'id');
            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: (string) $configuration->system_prompt,
                messages: [new AiChatMessage('user', $prompt)],
                parameters: is_array($configuration->parameters) ? $configuration->parameters : [],
            ));
            $parsed = $this->parser->parse($response->text, $chunkAllowed);
            $chunkResults[] = $parsed;
        }

        $final = count($chunkResults) === 1
            ? $chunkResults[0]
            : $this->reduce($chunkResults, $allowedIds, $configuration);

        $stats = $this->writer->persist(
            $group,
            $run,
            $final,
            $timezone,
            (string) $configuration->provider,
            (string) $configuration->model,
        );

        $truncated = count($lines) > 0 && array_sum(array_map('count', $chunks)) < count($lines);
        $metadata = array_merge($run->metadata ?? [], $stats, [
            'no_data' => false,
            'chunk_count' => count($chunks),
            'reduce_used' => count($chunks) > 1,
            'truncated' => $truncated,
            'message_count' => $messages->count(),
        ]);

        Log::info('telegram group analysis completed', [
            'run_id' => $run->id,
            'telegram_group_id' => $group->id,
            'from_at' => $run->from_at?->toIso8601String(),
            'to_at' => $run->to_at?->toIso8601String(),
            'chunk_count' => count($chunks),
            'provider' => $configuration->provider,
            'model' => $configuration->model,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'summaries' => $stats['summaries'],
            'decisions' => $stats['decisions'],
            'tasks' => $stats['tasks'],
            'events' => $stats['events'],
            'error_class' => null,
        ]);

        return [
            'provider' => (string) $configuration->provider,
            'model' => (string) $configuration->model,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  list<GroupAnalysisResult>  $chunks
     * @param  list<int>  $allowedIds
     */
    private function reduce(array $chunks, array $allowedIds, AiRoleSetting $configuration): GroupAnalysisResult
    {
        $encoded = array_map(
            static fn (GroupAnalysisResult $result): string => json_encode([
                'summary' => $result->summary === null ? null : [
                    'content' => $result->summary->content,
                    'confidence' => $result->summary->confidence,
                    'source_message_ids' => $result->summary->sourceMessageIds,
                ],
                'decisions' => array_map(static fn ($item): array => [
                    'content' => $item->content,
                    'confidence' => $item->confidence,
                    'source_message_ids' => $item->sourceMessageIds,
                    'participants' => $item->participants,
                    'effective_date_local' => $item->effectiveDateLocal,
                    'supersedes_normalized_key' => $item->supersedesNormalizedKey,
                    'thread_id' => $item->threadId,
                ], $result->decisions),
                'tasks' => array_map(static fn ($item): array => [
                    'content' => $item->content,
                    'assignee_text' => $item->assigneeText,
                    'due_at_local' => $item->dueAtLocal,
                    'status_hint' => $item->statusHint,
                    'confidence' => $item->confidence,
                    'source_message_ids' => $item->sourceMessageIds,
                    'supersedes_normalized_key' => $item->supersedesNormalizedKey,
                    'thread_id' => $item->threadId,
                ], $result->tasks),
                'events' => array_map(static fn ($item): array => [
                    'content' => $item->content,
                    'occurred_at_local' => $item->occurredAtLocal,
                    'confidence' => $item->confidence,
                    'source_message_ids' => $item->sourceMessageIds,
                    'supersedes_normalized_key' => $item->supersedesNormalizedKey,
                    'thread_id' => $item->threadId,
                ], $result->events),
            ], JSON_UNESCAPED_UNICODE),
            $chunks,
        );

        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: (string) $configuration->system_prompt,
            messages: [new AiChatMessage('user', $this->prompts->reduce($encoded))],
            parameters: is_array($configuration->parameters) ? $configuration->parameters : [],
        ));

        return $this->parser->parse($response->text, $allowedIds);
    }
}
