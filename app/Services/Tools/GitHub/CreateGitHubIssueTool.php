<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class CreateGitHubIssueTool extends GitHubTool
{
    public const NAME = 'create_github_issue';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a GitHub issue. Explicit user command is allowed; model-proposed create requires confirmation. Does not close or delete issues.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'title' => [
                        'type' => 'STRING',
                        'description' => 'Issue title.',
                    ],
                    'body' => [
                        'type' => 'STRING',
                        'description' => 'Optional issue body.',
                    ],
                    'labels' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional label names.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'assignees' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional GitHub logins. Do not invent identity.',
                        'items' => ['type' => 'STRING'],
                    ],
                ],
                'required' => ['repository', 'title'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    protected function confirmationHint(): ?string
    {
        return 'Create a GitHub issue.';
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        return $this->ok($call, $this->github->createIssue(
            $this->resolveAccount($context),
            $this->repository($call),
            $call->arguments,
        ));
    }
}
