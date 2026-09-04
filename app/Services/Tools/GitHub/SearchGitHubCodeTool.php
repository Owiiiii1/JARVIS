<?php

namespace App\Services\Tools\GitHub;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class SearchGitHubCodeTool extends GitHubTool
{
    public const NAME = 'search_github_code';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches GitHub code. Use for "найди где реализован ToolExecutionService". Results are bounded. GitHub search indexing can lag; report that honestly if results look incomplete.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Search query.',
                    ],
                    'repository' => [
                        'type' => 'STRING',
                        'description' => 'Optional repository to scope the search.',
                    ],
                    'path' => [
                        'type' => 'STRING',
                        'description' => 'Optional path qualifier.',
                    ],
                    'language' => [
                        'type' => 'STRING',
                        'description' => 'Optional language qualifier.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max results. Core caps this.',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = $this->optionalString($call, 'query');
        if ($query === null) {
            throw new IntegrationException('github_validation_failed', 'Search query is required.');
        }

        return $this->ok($call, $this->github->searchCode($this->resolveAccount($context), $query, [
            'repository' => $this->optionalString($call, 'repository'),
            'path' => $this->optionalString($call, 'path'),
            'language' => $this->optionalString($call, 'language'),
            'max_results' => $this->optionalInt($call, 'max_results'),
        ]));
    }
}
