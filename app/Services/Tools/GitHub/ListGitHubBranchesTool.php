<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGitHubBranchesTool extends GitHubTool
{
    public const NAME = 'list_github_branches';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists branches for a GitHub repository. Bounded. Returns name, head sha, and protected flag when available.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max branches. Core caps this.',
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
        return $this->ok($call, $this->github->listBranches(
            $this->resolveAccount($context),
            $this->repository($call),
            $this->optionalInt($call, 'max_results'),
        ));
    }
}
