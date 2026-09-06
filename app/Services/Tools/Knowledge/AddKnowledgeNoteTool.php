<?php

namespace App\Services\Tools\Knowledge;

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

final class AddKnowledgeNoteTool implements JarvisTool
{
    public const NAME = 'add_knowledge_note';

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
            description: 'Adds an explicit user note to a knowledge entity timeline. Non-destructive. Use when the user asks to record a note about a person or project already in knowledge.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'entity_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Knowledge entity id.',
                    ],
                    'note' => [
                        'type' => 'STRING',
                        'description' => 'Short note the user stated. Do not invent.',
                    ],
                ],
                'required' => ['entity_id', 'note'],
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
        $id = (int) ($call->arguments['entity_id'] ?? 0);
        $note = trim((string) ($call->arguments['note'] ?? ''));

        if ($id < 1 || $note === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Manual,
            fingerprint: KnowledgeSourceRef::hash('manual_note', (string) $context->user->id, (string) $id, md5($note), (string) $context->conversation->id),
            confidence: KnowledgeConfidence::manual(),
            conversationId: $context->conversation->id,
            manual: true,
        );

        try {
            $entity = $this->ingestion->requireOwnedEntity($context->user, $id);
            $event = $this->ingestion->addNote($context->user, $entity, $note, $source);
        } catch (KnowledgeException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'entity_id' => $entity->id,
            'event_id' => $event->id,
        ]);
    }
}
