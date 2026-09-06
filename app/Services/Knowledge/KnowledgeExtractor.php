<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeSourceType;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\Exceptions\KnowledgeExtractionException;
use App\Services\Users\UserCapability;

final class KnowledgeExtractor
{
    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
        private readonly KnowledgeExtractionPromptBuilder $prompts,
        private readonly KnowledgeExtractionParser $parser,
        private readonly KnowledgeIngestionService $ingestion,
    ) {}

    /**
     * @return array{stats: array<string, int>, provider: string, model: string}
     */
    public function extractFromMemory(User $user, Memory $memory): array
    {
        if ((int) $memory->user_id !== (int) $user->id) {
            throw new KnowledgeExtractionException('stale_source');
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Memory,
            fingerprint: KnowledgeSourceRef::hash('memory', (string) $memory->id, (string) $memory->updated_at),
            confidence: KnowledgeConfidence::fromLabel(null, (float) $memory->confidence),
            conversationId: $memory->sources()->orderByDesc('id')->value('conversation_id'),
            memoryId: $memory->id,
        );

        return $this->extract($user, KnowledgeSourceType::Memory, (string) $memory->content, $source);
    }

    /**
     * @return array{stats: array<string, int>, provider: string, model: string}
     */
    public function extractFromSummary(User $user, ConversationSummary $summary): array
    {
        if ((int) $summary->user_id !== (int) $user->id) {
            throw new KnowledgeExtractionException('stale_source');
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Summary,
            fingerprint: KnowledgeSourceRef::hash('summary', (string) $summary->id, (string) $summary->version),
            confidence: (float) config('knowledge.confidence.explicit_extraction', 0.85),
            conversationId: $summary->conversation_id,
        );

        return $this->extract($user, KnowledgeSourceType::Summary, (string) $summary->summary, $source);
    }

    /**
     * @return array{stats: array<string, int>, provider: string, model: string}
     */
    public function extract(User $user, KnowledgeSourceType $sourceType, string $text, KnowledgeSourceRef $source): array
    {
        if (! $user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return [
                'stats' => ['entities' => 0, 'relationships' => 0, 'events' => 0, 'skipped' => 0],
                'provider' => 'none',
                'model' => 'none',
            ];
        }

        $text = trim($text);

        if ($text === '') {
            return [
                'stats' => ['entities' => 0, 'relationships' => 0, 'events' => 0, 'skipped' => 0],
                'provider' => 'none',
                'model' => 'none',
            ];
        }

        $configuration = $this->resolver->resolveAnalysis();
        $response = $this->gateway->chat($configuration, new AiChatRequest(
            model: (string) $configuration->model,
            systemPrompt: $this->prompts->systemPrompt(),
            messages: [new AiChatMessage('user', $this->prompts->userPrompt($sourceType->value, $text))],
            parameters: [
                'temperature' => 0.1,
                'max_tokens' => 1200,
            ],
        ));

        $parsed = $this->parser->parse((string) $response->text);
        $stats = $this->ingestion->applyExtraction($user, $parsed, $source);

        return [
            'stats' => $stats,
            'provider' => (string) $response->provider,
            'model' => (string) $response->model,
        ];
    }
}
