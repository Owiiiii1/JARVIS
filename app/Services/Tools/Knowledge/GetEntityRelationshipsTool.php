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

final class GetEntityRelationshipsTool implements JarvisTool
{
    public const NAME = 'get_entity_relationships';

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
            description: 'Lists compact active relationships for one knowledge entity owned by the current user.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'entity_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Knowledge entity id.',
                    ],
                    'limit' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max rows. Core caps this.',
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
            $rows = $this->knowledge->relationships(
                $context->user,
                $id,
                isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : 8,
            );
        } catch (KnowledgeException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'entity_id' => $id,
            'count' => count($rows),
            'relationships' => $rows,
        ]);
    }
}
