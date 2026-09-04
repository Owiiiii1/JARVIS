<?php

namespace App\Services\Tools;

use App\Enums\ProjectStatus;
use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Projects\ProjectContextService;
use App\Services\Users\UserCapability;

final class GetProjectContextTool implements JarvisTool
{
    public const NAME = 'get_project_context';

    public function __construct(
        private readonly ProjectContextService $context,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Loads compact derived context for one of the current owner’s projects (description, attached topics, memories, conversation summaries, and bounded group-derived knowledge). Use when the user asks about a named project such as JARVIS, YFS, or RTS. Does not return raw chat transcripts. Never invent project facts if the project has no attached context.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project' => [
                        'type' => 'STRING',
                        'description' => 'Project name, for example JARVIS or RTS.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional focus query inside the project.',
                    ],
                ],
                'required' => ['project'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::PROJECTS,
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::PROJECTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $name = trim((string) ($call->arguments['project'] ?? ''));
        $query = isset($call->arguments['query']) ? trim((string) $call->arguments['query']) : null;

        if ($name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $matches = $this->context->resolve($context->user, $name);
        } catch (ProjectException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        if ($matches === []) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'project_not_found',
            ]);
        }

        if (count($matches) > 1) {
            return ToolResult::success($call->id, $this->name(), [
                'success' => true,
                'error' => 'ambiguous',
                'candidates' => array_map(static fn ($project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status instanceof ProjectStatus ? $project->status->value : (string) $project->status,
                ], $matches),
            ]);
        }

        $payload = $this->context->context($context->user, $matches[0], $query !== '' ? $query : null);
        $payload['success'] = true;

        return ToolResult::success($call->id, $this->name(), $payload);
    }
}
