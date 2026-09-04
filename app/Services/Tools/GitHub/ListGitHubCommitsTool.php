<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGitHubCommitsTool extends GitHubTool
{
    public const NAME = 'list_github_commits';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists commits for a GitHub repository. Use since=today (ISO datetime) for "что изменилось сегодня". Bounded. Follow with get_github_commit for a specific sha.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'branch' => [
                        'type' => 'STRING',
                        'description' => 'Optional branch or ref.',
                    ],
                    'ref' => [
                        'type' => 'STRING',
                        'description' => 'Optional ref. Alias of branch.',
                    ],
                    'since' => [
                        'type' => 'STRING',
                        'description' => 'Optional ISO8601 lower bound.',
                    ],
                    'until' => [
                        'type' => 'STRING',
                        'description' => 'Optional ISO8601 upper bound.',
                    ],
                    'author' => [
                        'type' => 'STRING',
                        'description' => 'Optional GitHub login or name.',
                    ],
                    'path' => [
                        'type' => 'STRING',
                        'description' => 'Optional file path filter.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max commits. Core caps this.',
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
        return $this->ok($call, $this->github->listCommits($this->resolveAccount($context), $this->repository($call), [
            'branch' => $this->optionalString($call, 'branch'),
            'ref' => $this->optionalString($call, 'ref'),
            'since' => $this->optionalString($call, 'since'),
            'until' => $this->optionalString($call, 'until'),
            'author' => $this->optionalString($call, 'author'),
            'path' => $this->optionalString($call, 'path'),
            'max_results' => $this->optionalInt($call, 'max_results'),
        ]));
    }
}
