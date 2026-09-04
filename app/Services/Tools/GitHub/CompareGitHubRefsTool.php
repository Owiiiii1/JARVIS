<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class CompareGitHubRefsTool extends GitHubTool
{
    public const NAME = 'compare_github_refs';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Compares two GitHub refs (commits or branches) via the compare API. Use for diffs between two commits. Bounded files and patches.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Repository full_name or short name.',
                    ],
                    'base' => [
                        'type' => 'STRING',
                        'description' => 'Base ref or sha.',
                    ],
                    'head' => [
                        'type' => 'STRING',
                        'description' => 'Head ref or sha.',
                    ],
                ],
                'required' => ['repository', 'base', 'head'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $base = $this->optionalString($call, 'base');
        $head = $this->optionalString($call, 'head');
        if ($base === null || $head === null) {
            throw new IntegrationException('github_validation_failed', 'Base and head refs are required.');
        }

        return $this->ok($call, $this->github->compareRefs(
            $this->resolveAccount($context),
            $this->repository($call),
            $base,
            $head,
        ));
    }
}
