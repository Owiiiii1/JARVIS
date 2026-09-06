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

final class GetPersonStatusTool implements JarvisTool
{
    public const NAME = 'get_person_status';

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
            description: 'Bounded person synthesis from the current user’s Knowledge graph: who, last indexed activity, open loops, waiting, commitments, related projects. Foreign entity ids fail. Last activity is unknown when no interaction events exist.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'entity_id' => ['type' => 'INTEGER', 'description' => 'Owned knowledge person entity id.'],
                    'person' => ['type' => 'STRING', 'description' => 'Person name in the user’s graph.'],
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
        $entityId = isset($call->arguments['entity_id']) ? (int) $call->arguments['entity_id'] : 0;
        $name = trim((string) ($call->arguments['person'] ?? $call->arguments['name'] ?? ''));

        if ($entityId < 1 && $name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::PersonStatus,
                entityId: $entityId > 0 ? $entityId : null,
                personName: $name !== '' ? $name : null,
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
