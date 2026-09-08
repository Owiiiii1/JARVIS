<?php

namespace Tests\Support;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class CountingFakeTool implements JarvisTool
{
    public int $executions = 0;

    /**
     * @param  array<string, mixed>|\Closure(ToolCall, ToolExecutionContext): array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $toolName,
        private readonly ToolOperationClass $operation = ToolOperationClass::Read,
        private readonly string $capability = UserCapability::STORAGE,
        private readonly ?string $provider = null,
        private readonly mixed $payload = ['success' => true],
        private readonly bool $fail = false,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: $this->toolName,
            description: 'Test-only counted tool.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING'],
                    'file_id' => ['type' => 'STRING'],
                    'start' => ['type' => 'INTEGER'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: $this->capability,
            operation: $this->operation,
            provider: $this->provider,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability($this->capability);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $this->executions++;

        $payload = $this->payload instanceof \Closure
            ? ($this->payload)($call, $context)
            : $this->payload;

        if ($this->fail) {
            return ToolResult::failure($call->id, $this->name(), array_merge([
                'success' => false,
                'error' => 'fake_failed',
            ], is_array($payload) ? $payload : []));
        }

        return ToolResult::success($call->id, $this->name(), array_merge([
            'success' => true,
        ], is_array($payload) ? $payload : []));
    }
}
