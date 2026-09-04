<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGitHubFileTool extends GitHubTool
{
    public const NAME = 'get_github_file';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads a text file from a GitHub repository. Returns bounded UTF-8 content with truncated=true when capped. Rejects binary files. Do not use for archives.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'path' => [
                        'type' => 'STRING',
                        'description' => 'File path inside the repository.',
                    ],
                    'ref' => [
                        'type' => 'STRING',
                        'description' => 'Optional branch, tag, or sha. Defaults to the default branch.',
                    ],
                ],
                'required' => ['repository', 'path'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $path = $this->optionalString($call, 'path');
        if ($path === null) {
            throw new IntegrationException('github_validation_failed', 'File path is required.');
        }

        return $this->ok($call, $this->github->getFile(
            $this->resolveAccount($context),
            $this->repository($call),
            $path,
            $this->optionalString($call, 'ref'),
        ));
    }
}
