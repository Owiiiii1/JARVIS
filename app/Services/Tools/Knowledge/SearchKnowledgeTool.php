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

final class SearchKnowledgeTool implements JarvisTool
{
    public const NAME = 'search_knowledge';

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
            description: 'Searches the current user’s structured knowledge (people, projects, systems, aliases). Returns compact candidates, never a full graph. Use when the user asks who/what something is, related people, or recent activity for a named entity.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Name, alias, or keywords.',
                    ],
                    'type' => [
                        'type' => 'STRING',
                        'description' => 'Optional entity type: person, project, organization, product, place, topic, system, file, custom.',
                    ],
                    'project_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional project id to prefer entities linked to that Project.',
                    ],
                    'limit' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max candidates. Core caps this.',
                    ],
                ],
                'required' => ['query'],
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
        $query = trim((string) ($call->arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $results = $this->knowledge->search(
                $context->user,
                $query,
                isset($call->arguments['type']) ? (string) $call->arguments['type'] : null,
                isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
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
            'count' => count($results),
            'entities' => $results,
        ]);
    }
}
