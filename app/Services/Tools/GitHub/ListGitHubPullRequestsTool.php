<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGitHubPullRequestsTool extends GitHubTool
{
    public const NAME = 'list_github_pull_requests';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists GitHub pull requests. Use for "какие открытые PR". Does not merge. Bounded.',
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
                    'base' => [
                        'type' => 'STRING',
                        'description' => 'Optional base branch.',
                    ],
                    'head' => [
                        'type' => 'STRING',
                        'description' => 'Optional head branch.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max pull requests. Core caps this.',
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
        return $this->ok($call, $this->github->listPullRequests($this->resolveAccount($context), $this->repository($call), [
            'state' => $this->optionalString($call, 'state'),
            'base' => $this->optionalString($call, 'base'),
            'head' => $this->optionalString($call, 'head'),
            'max_results' => $this->optionalInt($call, 'max_results'),
        ]));
    }
}
