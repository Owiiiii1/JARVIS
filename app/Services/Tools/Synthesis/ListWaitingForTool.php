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

final class ListWaitingForTool implements JarvisTool
{
    public const NAME = 'list_waiting_for';

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
            description: 'Lists derived waiting-for items (one-shot watchers, explicit waiting_on relations, external-dependency tasks). Not every open task. Includes since, source, suggested follow-up timing.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project_id' => ['type' => 'INTEGER'],
                    'project' => ['type' => 'STRING'],
                    'entity_id' => ['type' => 'INTEGER'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::KNOWLEDGE, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::KNOWLEDGE);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::WaitingFor,
                projectId: isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                projectName: isset($call->arguments['project']) ? trim((string) $call->arguments['project']) : null,
                entityId: isset($call->arguments['entity_id']) ? (int) $call->arguments['entity_id'] : null,
                withNarrative: false,
            ));
        } catch (SynthesisException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'waiting_for' => $result->toArray()['waiting_for'] ?? [],
            'freshness' => $result->freshness,
            'generated_at' => $result->generatedAt->toIso8601String(),
            'sources' => $result->sources,
        ]);
    }
}
