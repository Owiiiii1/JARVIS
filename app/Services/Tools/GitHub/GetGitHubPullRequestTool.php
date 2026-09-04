<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGitHubPullRequestTool extends GitHubTool
{
    public const NAME = 'get_github_pull_request';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Gets one GitHub pull request with metadata and a bounded changed-file list. Does not merge.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'pull_number' => [
                        'type' => 'INTEGER',
                        'description' => 'Pull request number.',
                    ],
                ],
                'required' => ['repository', 'pull_number'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $number = $this->optionalInt($call, 'pull_number');
        if ($number === null) {
            throw new IntegrationException('github_validation_failed', 'Pull request number is required.');
        }

        return $this->ok($call, $this->github->getPullRequest(
            $this->resolveAccount($context),
            $this->repository($call),
            $number,
        ));
    }
}
