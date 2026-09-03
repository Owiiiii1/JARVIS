<?php

namespace App\Services\Tools;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Memory\ConversationHistorySearch;
use App\Services\Users\UserCapability;

final class SearchConversationHistoryTool implements JarvisTool
{
    public const NAME = 'search_conversation_history';

    public function __construct(
        private readonly ConversationHistorySearch $search,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches the current user’s own conversation history for relevant snippets. Use when the user asks about a past chat, decision, or detail that is not in the current context. Never invent other users.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Search query, keywords, or the detail the user is asking about.',
                    ],
                    'conversation_hint' => [
                        'type' => 'STRING',
                        'description' => 'Optional conversation title hint, for example "Unreal" or "Работа".',
                    ],
                    'limit' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional maximum number of snippets. Core caps this.',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::MEMORY);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));
        $hint = isset($call->arguments['conversation_hint'])
            ? trim((string) $call->arguments['conversation_hint'])
            : null;
        $limit = isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : null;

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $snippets = $this->search->search(
            user: $context->user,
            query: $query,
            conversationHint: $hint !== '' ? $hint : null,
            limit: $limit,
        );

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($snippets),
            'snippets' => $snippets,
        ]);
    }
}
