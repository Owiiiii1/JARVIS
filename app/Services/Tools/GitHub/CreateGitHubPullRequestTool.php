<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class CreateGitHubPullRequestTool extends GitHubTool
{
    public const NAME = 'create_github_pull_request';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a GitHub pull request. Does not merge. If an open PR already exists for the same head/base, returns that PR. Explicit user command is allowed; model-proposed create requires confirmation.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'title' => [
                        'type' => 'STRING',
                        'description' => 'Pull request title.',
                    ],
                    'body' => [
                        'type' => 'STRING',
                        'description' => 'Optional pull request body.',
                    ],
                    'head' => [
                        'type' => 'STRING',
                        'description' => 'Head branch name.',
                    ],
                    'base' => [
                        'type' => 'STRING',
                        'description' => 'Optional base branch. Defaults to the repository default branch.',
                    ],
                    'draft' => [
                        'type' => 'BOOLEAN',
                        'description' => 'Optional draft flag.',
                    ],
                ],
                'required' => ['repository', 'title', 'head'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    protected function confirmationHint(): ?string
    {
        return 'Create a GitHub pull request. It will not be merged.';
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $title = $this->optionalString($call, 'title');
        $head = $this->optionalString($call, 'head');
        if ($title === null || $head === null) {
            throw new IntegrationException('github_validation_failed', 'Title and head branch are required.');
        }

        return $this->ok($call, $this->github->createPullRequest(
            $this->resolveAccount($context),
            $this->repository($call),
            $call->arguments,
        ));
    }
}
