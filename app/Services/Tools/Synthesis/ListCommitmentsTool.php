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

final class ListCommitmentsTool implements JarvisTool
{
    public const NAME = 'list_commitments';

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
            description: 'Lists explicit commitments only (not every Task). mode=mine (user promised), others (someone promised the user), or all.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'mode' => ['type' => 'STRING', 'description' => 'mine | others | all'],
                    'project_id' => ['type' => 'INTEGER'],
                    'project' => ['type' => 'STRING'],
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
        $mode = mb_strtolower(trim((string) ($call->arguments['mode'] ?? 'all')));

        if (! in_array($mode, ['mine', 'others', 'all'], true)) {
            $mode = 'all';
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::Commitments,
                projectId: isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                projectName: isset($call->arguments['project']) ? trim((string) $call->arguments['project']) : null,
                commitmentMode: $mode,
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
            'mode' => $mode,
            'commitments' => $result->toArray()['commitments'] ?? [],
            'generated_at' => $result->generatedAt->toIso8601String(),
            'sources' => $result->sources,
        ]);
    }
}
