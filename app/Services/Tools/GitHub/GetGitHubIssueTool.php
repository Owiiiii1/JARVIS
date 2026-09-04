<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGitHubIssueTool extends GitHubTool
{
    public const NAME = 'get_github_issue';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Gets one GitHub issue with a bounded body and comment list. Does not dump hundreds of comments.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'issue_number' => [
                        'type' => 'INTEGER',
                        'description' => 'Issue number.',
                    ],
                ],
                'required' => ['repository', 'issue_number'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $number = $this->optionalInt($call, 'issue_number');
        if ($number === null) {
            throw new IntegrationException('github_validation_failed', 'Issue number is required.');
        }

        return $this->ok($call, $this->github->getIssue(
            $this->resolveAccount($context),
            $this->repository($call),
            $number,
        ));
    }
}
