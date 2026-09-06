<?php

namespace App\Services\Tools\Knowledge;

use App\Enums\KnowledgeEntityType;
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

final class RememberEntityTool implements JarvisTool
{
    public const NAME = 'remember_entity';

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
            description: 'Creates or links a structured knowledge entity from an explicit user statement (person, project index, system, etc.). Does not invent contact details. Does not merge duplicates aggressively. Use when the user asks to remember who/what something is.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => [
                        'type' => 'STRING',
                        'description' => 'Canonical name stated by the user.',
                    ],
                    'entity_type' => [
                        'type' => 'STRING',
                        'description' => 'person, project, organization, product, place, topic, system, file, or custom.',
                    ],
                    'summary' => [
                        'type' => 'STRING',
                        'description' => 'Optional short sourced summary. Do not invent.',
                    ],
                    'aliases' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional aliases the user stated, e.g. YFS.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'project_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional existing Project id this entity indexes. Project remains canonical.',
                    ],
                ],
                'required' => ['name', 'entity_type'],
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
        $name = trim((string) ($call->arguments['name'] ?? ''));
        $type = KnowledgeEntityType::tryFromLoose($call->arguments['entity_type'] ?? null);

        if ($name === '' || $type === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Manual,
            fingerprint: KnowledgeSourceRef::hash('manual_entity', (string) $context->user->id, $type->value, mb_strtolower($name), (string) $context->conversation->id),
            confidence: KnowledgeConfidence::manual(),
            conversationId: $context->conversation->id,
            projectId: isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
            manual: true,
        );

        try {
            $entity = $this->ingestion->upsertEntity($context->user, $type, $name, $source, [
                'summary' => $call->arguments['summary'] ?? null,
                'aliases' => is_array($call->arguments['aliases'] ?? null) ? $call->arguments['aliases'] : [],
                'project_id' => $source->projectId,
                'confidence' => KnowledgeConfidence::manual(),
            ]);
        } catch (KnowledgeException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'entity_id' => $entity->id,
            'name' => $entity->name,
            'type' => $entity->type->value,
        ]);
    }
}
