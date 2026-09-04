<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGitHubCommitTool extends GitHubTool
{
    public const NAME = 'get_github_commit';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Gets one GitHub commit with bounded changed files and patches. Use after list_github_commits. Does not return multi-megabyte diffs.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'sha' => [
                        'type' => 'STRING',
                        'description' => 'Commit sha or ref.',
                    ],
                    'ref' => [
                        'type' => 'STRING',
                        'description' => 'Alias of sha.',
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
        $sha = $this->optionalString($call, 'sha') ?? $this->optionalString($call, 'ref');
        if ($sha === null) {
            throw new IntegrationException('github_validation_failed', 'Commit sha is required.');
        }

        return $this->ok($call, $this->github->getCommit(
            $this->resolveAccount($context),
            $this->repository($call),
            $sha,
        ));
    }
}
