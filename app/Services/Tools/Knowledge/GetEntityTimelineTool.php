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
use Carbon\CarbonImmutable;

final class GetEntityTimelineTool implements JarvisTool
{
    public const NAME = 'get_entity_timeline';

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
            description: 'Returns compact recent timeline events for one knowledge entity owned by the current user. Use for “what happened with X”. Follow source ids with existing tools for detail.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'entity_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Knowledge entity id.',
                    ],
                    'since' => [
                        'type' => 'STRING',
                        'description' => 'Optional ISO datetime lower bound.',
                    ],
                    'limit' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max events. Core caps this.',
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

        $since = null;

        if (isset($call->arguments['since']) && trim((string) $call->arguments['since']) !== '') {
            try {
                $since = CarbonImmutable::parse((string) $call->arguments['since'])->utc();
            } catch (\Throwable) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => 'invalid_arguments',
                ]);
            }
        }

        try {
            $events = $this->knowledge->timeline(
                $context->user,
                $id,
                isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : 8,
                $since,
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
            'count' => count($events),
            'events' => $events,
        ]);
    }
}
