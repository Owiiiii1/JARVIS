<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGitHubWorkflowRunsTool extends GitHubTool
{
    public const NAME = 'list_github_workflow_runs';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists GitHub Actions workflow runs. Use for "что сейчас с CI". Does not download logs. Bounded.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'branch' => [
                        'type' => 'STRING',
                        'description' => 'Optional branch filter.',
                    ],
                    'status' => [
                        'type' => 'STRING',
                        'description' => 'Optional status, for example completed or in_progress.',
                    ],
                    'event' => [
                        'type' => 'STRING',
                        'description' => 'Optional event, for example push or pull_request.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max runs. Core caps this.',
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
        return $this->ok($call, $this->github->listWorkflowRuns($this->resolveAccount($context), $this->repository($call), [
            'branch' => $this->optionalString($call, 'branch'),
            'status' => $this->optionalString($call, 'status'),
            'event' => $this->optionalString($call, 'event'),
            'max_results' => $this->optionalInt($call, 'max_results'),
        ]));
    }
}
