<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class CommentGitHubIssueTool extends GitHubTool
{
    public const NAME = 'comment_github_issue';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Adds a comment on a GitHub issue or pull request number. Explicit user command is allowed; model-proposed comment requires confirmation.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'issue_number' => [
                        'type' => 'INTEGER',
                        'description' => 'Issue or pull request number.',
                    ],
                    'body' => [
                        'type' => 'STRING',
                        'description' => 'Comment body.',
                    ],
                ],
                'required' => ['repository', 'issue_number', 'body'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    protected function confirmationHint(): ?string
    {
        return 'Add a GitHub issue or pull request comment.';
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $number = $this->optionalInt($call, 'issue_number');
        $body = $this->optionalString($call, 'body');
        if ($number === null || $body === null) {
            throw new IntegrationException('github_validation_failed', 'Issue number and body are required.');
        }

        return $this->ok($call, $this->github->commentIssue(
            $this->resolveAccount($context),
            $this->repository($call),
            $number,
            $body,
        ));
    }
}
