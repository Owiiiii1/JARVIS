<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGitHubIssuesTool extends GitHubTool
{
    public const NAME = 'list_github_issues';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists GitHub issues. Pull requests are filtered out. Use for "какие открытые issues". Bounded.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'state' => [
                        'type' => 'STRING',
                        'description' => 'open, closed, or all. Default open.',
                    ],
                    'labels' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional label names.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'assignee' => [
                        'type' => 'STRING',
                        'description' => 'Optional assignee login.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional title filter.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max issues. Core caps this.',
                    ],
                ],
                'required' => ['repository'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        return $this->ok($call, $this->github->listIssues($this->resolveAccount($context), $this->repository($call), [
            'state' => $this->optionalString($call, 'state'),
            'labels' => $call->arguments['labels'] ?? [],
            'assignee' => $this->optionalString($call, 'assignee'),
            'query' => $this->optionalString($call, 'query'),
            'max_results' => $this->optionalInt($call, 'max_results'),
        ]));
    }
}
