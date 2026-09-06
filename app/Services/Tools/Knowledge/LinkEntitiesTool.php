<?php

namespace App\Services\Tools\Knowledge;

use App\Enums\KnowledgeRelationType;
use App\Enums\KnowledgeSourceType;
use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\Exceptions\KnowledgeException;
use App\Services\Knowledge\KnowledgeConfidence;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class LinkEntitiesTool implements JarvisTool
{
    public const NAME = 'link_entities';

    public function __construct(
        private readonly KnowledgeIngestionService $ingestion,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates or deactivates a typed relationship between two knowledge entities the current user owns. Use when the user explicitly states a link or that a link ended. Does not delete history.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'source_entity_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Source entity id.',
                    ],
                    'target_entity_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Target entity id.',
                    ],
                    'relation_type' => [
                        'type' => 'STRING',
                        'description' => 'works_on, works_for, uses, depends_on, has_resource, mentioned_in, client_of, related_to, owns, participates_in.',
                    ],
                    'label' => [
                        'type' => 'STRING',
                        'description' => 'Optional short label.',
                    ],
                    'deactivate' => [
                        'type' => 'BOOLEAN',
                        'description' => 'If true, mark the relationship inactive and keep timeline history.',
                    ],
                ],
                'required' => ['source_entity_id', 'target_entity_id', 'relation_type'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::KNOWLEDGE,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::KNOWLEDGE);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $sourceId = (int) ($call->arguments['source_entity_id'] ?? 0);
        $targetId = (int) ($call->arguments['target_entity_id'] ?? 0);
        $type = KnowledgeRelationType::tryFromLoose($call->arguments['relation_type'] ?? null);

        if ($sourceId < 1 || $targetId < 1 || $type === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Manual,
            fingerprint: KnowledgeSourceRef::hash('manual_rel', (string) $context->user->id, (string) $sourceId, $type->value, (string) $targetId, (string) $context->conversation->id),
            confidence: KnowledgeConfidence::manual(),
            conversationId: $context->conversation->id,
            manual: true,
        );

        try {
            $from = $this->ingestion->requireOwnedEntity($context->user, $sourceId);
            $to = $this->ingestion->requireOwnedEntity($context->user, $targetId);
            $relation = $this->ingestion->upsertRelationship(
                $context->user,
                $from,
                $to,
                $type,
                $source,
                isset($call->arguments['label']) ? (string) $call->arguments['label'] : null,
                (bool) ($call->arguments['deactivate'] ?? false),
            );
        } catch (KnowledgeException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'relationship_id' => $relation->id,
            'status' => $relation->status->value,
            'type' => $relation->type->value,
        ]);
    }
}
