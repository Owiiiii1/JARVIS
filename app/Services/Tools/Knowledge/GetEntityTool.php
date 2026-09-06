<?php

namespace App\Services\Tools\Knowledge;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Knowledge\Exceptions\KnowledgeException;
use App\Services\Knowledge\KnowledgeRetriever;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetEntityTool implements JarvisTool
{
    public const NAME = 'get_entity';

    public function __construct(
        private readonly KnowledgeRetriever $knowledge,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Loads a compact knowledge entity: name, type, summary, aliases, top relationships, recent timeline, source count, linked Project. Never dumps raw messages. Foreign entity ids fail.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'entity_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Knowledge entity id from search_knowledge or context.',
                    ],
                ],
                'required' => ['entity_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::KNOWLEDGE,
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::KNOWLEDGE);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = (int) ($call->arguments['entity_id'] ?? 0);

        if ($id < 1) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $entity = $this->knowledge->getEntity($context->user, $id);
        } catch (KnowledgeException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error === 'not_found' ? 'not_found' : $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'entity' => $entity,
            'entity_id' => $entity['id'],
        ]);
    }
}
