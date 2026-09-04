<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGitHubRepositoriesTool extends GitHubTool
{
    public const NAME = 'list_github_repositories';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists GitHub repositories visible to the connected owner account. Use for "какие у меня репозитории" and to resolve a short name like JARVIS. Bounded. Does not dump permissions.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional name/description filter.',
                    ],
                    'visibility' => [
                        'type' => 'STRING',
                        'description' => 'Optional visibility: all, public, or private.',
                    ],
                    'affiliation' => [
                        'type' => 'STRING',
                        'description' => 'Optional affiliation filter, for example owner,collaborator,organization_member.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max repositories. Core caps this.',
                    ],
                ],
                'required' => [],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        return $this->ok($call, $this->github->listRepositories($this->resolveAccount($context), [
            'query' => $this->optionalString($call, 'query'),
            'visibility' => $this->optionalString($call, 'visibility'),
            'affiliation' => $this->optionalString($call, 'affiliation'),
            'max_results' => $this->optionalInt($call, 'max_results'),
        ]));
    }
}
