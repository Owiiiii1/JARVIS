<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class CreateGitHubBranchTool extends GitHubTool
{
    public const NAME = 'create_github_branch';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a GitHub branch from a ref (default: repository default branch). Does not overwrite, force-push, or delete branches. Explicit user command is allowed; model-proposed create requires confirmation.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'branch_name' => [
                        'type' => 'STRING',
                        'description' => 'New branch name.',
                    ],
                    'from_ref' => [
                        'type' => 'STRING',
                        'description' => 'Optional source branch or sha. Defaults to the repository default branch.',
                    ],
                ],
                'required' => ['repository', 'branch_name'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    protected function confirmationHint(): ?string
    {
        return 'Create a GitHub branch.';
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $branch = $this->optionalString($call, 'branch_name');
        if ($branch === null) {
            throw new IntegrationException('github_validation_failed', 'Branch name is required.');
        }

        return $this->ok($call, $this->github->createBranch(
            $this->resolveAccount($context),
            $this->repository($call),
            $branch,
            $this->optionalString($call, 'from_ref'),
        ));
    }
}
