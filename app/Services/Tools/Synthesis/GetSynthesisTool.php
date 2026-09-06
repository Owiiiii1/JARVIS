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
use App\Services\Synthesis\SynthesisClock;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetSynthesisTool implements JarvisTool
{
    public const NAME = 'get_synthesis';

    public function __construct(
        private readonly CrossSourceSynthesisService $synthesis,
        private readonly SynthesisClock $clock = new SynthesisClock,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Cross-source synthesis over the current user’s indexed Knowledge, Tasks, Reminders, Watchers, and Projects. Types: project_status, person_status, waiting_for, commitments, blockers, recent_changes, attention_needed, daily_digest, weekly_digest. Does not poll Gmail/Calendar/GitHub. Not a dump of search results.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'type' => [
                        'type' => 'STRING',
                        'description' => 'project_status | person_status | waiting_for | commitments | blockers | recent_changes | attention_needed | daily_digest | weekly_digest',
                    ],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Optional owned project id.'],
                    'project' => ['type' => 'STRING', 'description' => 'Optional project name.'],
                    'entity_id' => ['type' => 'INTEGER', 'description' => 'Optional knowledge entity id.'],
                    'person' => ['type' => 'STRING', 'description' => 'Optional person name.'],
                    'time_window' => ['type' => 'STRING', 'description' => 'Window such as 7d or 24h. Default 7d.'],
                    'commitment_mode' => ['type' => 'STRING', 'description' => 'mine | others | all'],
                ],
                'required' => ['type'],
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
        $type = SynthesisType::tryFromLoose($call->arguments['type'] ?? null);

        if ($type === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: $type,
                projectId: isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                projectName: isset($call->arguments['project']) ? trim((string) $call->arguments['project']) : null,
                entityId: isset($call->arguments['entity_id']) ? (int) $call->arguments['entity_id'] : null,
                personName: isset($call->arguments['person']) ? trim((string) $call->arguments['person']) : null,
                windowDays: $this->clock->parseWindowDays($call->arguments['time_window'] ?? $call->arguments['window'] ?? 7),
                commitmentMode: mb_strtolower((string) ($call->arguments['commitment_mode'] ?? 'all')),
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
