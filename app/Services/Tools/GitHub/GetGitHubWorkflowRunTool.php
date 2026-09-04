<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGitHubWorkflowRunTool extends GitHubTool
{
    public const NAME = 'get_github_workflow_run';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Gets one GitHub Actions workflow run plus a bounded jobs summary. Does not download logs.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'run_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Workflow run id.',
                    ],
                ],
                'required' => ['repository', 'run_id'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $runId = $this->optionalInt($call, 'run_id');
        if ($runId === null) {
            throw new IntegrationException('github_validation_failed', 'Workflow run id is required.');
        }

        return $this->ok($call, $this->github->getWorkflowRun(
            $this->resolveAccount($context),
            $this->repository($call),
            $runId,
        ));
    }
}
