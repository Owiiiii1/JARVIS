<?php

namespace App\Services\Tools\Synthesis;

use App\Enums\SynthesisType;
use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\Exceptions\SynthesisException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetProjectStatusTool implements JarvisTool
{
    public const NAME = 'get_project_status';

    public function __construct(
        private readonly CrossSourceSynthesisService $synthesis,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Cross-source current status for one owned project: summary, recent changes, open work, blockers, waiting-for, people, upcoming, risks, sources, freshness. Does not replace get_project_context. Does not poll integrations.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id.'],
                    'project' => ['type' => 'STRING', 'description' => 'Project name such as YFS or JARVIS.'],
                    'time_window' => ['type' => 'STRING', 'description' => 'Optional window, default 7d.'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::PROJECTS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::PROJECTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $projectId = isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : 0;
        $name = trim((string) ($call->arguments['project'] ?? ''));

        if ($projectId < 1 && $name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::ProjectStatus,
                projectId: $projectId > 0 ? $projectId : null,
                projectName: $name !== '' ? $name : null,
            ));
        } catch (SynthesisException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$result->toArray(),
        ]);
    }
}
