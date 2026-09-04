<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Enums\UserRole;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Groups\DTO\GroupSearchRequest;
use App\Services\Groups\Exceptions\GroupSearchException;
use App\Services\Groups\GroupKnowledgeSearchService;
use App\Services\Users\UserCapability;

final class SearchGroupKnowledgeTool implements JarvisTool
{
    public const NAME = 'search_group_knowledge';

    public function __construct(
        private readonly GroupKnowledgeSearchService $search,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches Telegram group discussions: stored summaries, decisions, tasks, events, and bounded raw snippets when needed. Use when the user asks about Telegram groups, group discussions, group decisions, tasks, events, what someone said in a group, or cross-group activity. Do not use for personal old chats (search_conversation_history), general project context across chats/topics/memories (get_project_context), or reminders (create_reminder). Never invent group facts if the tool returns no matches. Do not pass user_id, telegram_group_id, or raw SQL.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'What to look for: a topic, person, decision, task, or event.',
                    ],
                    'group' => [
                        'type' => 'STRING',
                        'description' => 'Optional group title, for example "Dev Team".',
                    ],
                    'project' => [
                        'type' => 'STRING',
                        'description' => 'Optional project name to limit search to attached groups, for example JARVIS.',
                    ],
                    'range' => [
                        'type' => 'STRING',
                        'description' => 'Optional range: today, yesterday, last_7_days, or custom.',
                    ],
                    'date_from' => [
                        'type' => 'STRING',
                        'description' => 'Inclusive local start date Y-m-d when range is custom.',
                    ],
                    'date_to' => [
                        'type' => 'STRING',
                        'description' => 'Inclusive local end date Y-m-d when range is custom.',
                    ],
                    'types' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional knowledge types: summary, decision, task, event_fact.',
                        'items' => [
                            'type' => 'STRING',
                        ],
                    ],
                    'include_raw_if_needed' => [
                        'type' => 'BOOLEAN',
                        'description' => 'If true, include bounded raw snippets even when derived knowledge exists.',
                    ],
                    'limit' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max derived items per group. Core caps this.',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::GROUP_ANALYSIS,
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->role === UserRole::Owner
            && $context->user->canUseCapability(UserCapability::GROUP_ANALYSIS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        if (! $this->isAvailable($context)) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'tool_not_available',
            ]);
        }

        $query = trim((string) ($call->arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $types = $call->arguments['types'] ?? [];
        if (! is_array($types)) {
            $types = [];
        }

        try {
            $payload = $this->search->search(new GroupSearchRequest(
                user: $context->user,
                query: $query,
                groupHint: $this->optionalString($call->arguments['group'] ?? null),
                projectHint: $this->optionalString($call->arguments['project'] ?? null),
                range: $this->optionalString($call->arguments['range'] ?? null),
                dateFrom: $this->optionalString($call->arguments['date_from'] ?? null),
                dateTo: $this->optionalString($call->arguments['date_to'] ?? null),
                types: array_values(array_filter($types, 'is_string')),
                includeRawIfNeeded: (bool) ($call->arguments['include_raw_if_needed'] ?? false),
                limit: isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : null,
            ));
        } catch (GroupSearchException $exception) {
            if ($exception->error === 'ambiguous_project') {
                $candidates = json_decode($exception->getMessage(), true);

                return ToolResult::success($call->id, $this->name(), [
                    'success' => true,
                    'error' => 'ambiguous_project',
                    'candidates' => is_array($candidates) ? $candidates : [],
                ]);
            }

            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
                'message' => $exception->getMessage(),
            ]);
        }

        return ($payload['success'] ?? false) === false
            ? ToolResult::failure($call->id, $this->name(), $payload)
            : ToolResult::success($call->id, $this->name(), $payload);
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
