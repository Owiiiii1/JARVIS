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
use Throwable;

final class FakeIntegrationTool implements JarvisTool
{
    public function __construct(
        private readonly string $toolName,
        private readonly ToolOperationClass $operation = ToolOperationClass::Read,
        private readonly string $capability = UserCapability::GOOGLE_CALENDAR,
        private readonly ?string $provider = 'fake',
        private readonly bool $fail = false,
        private readonly array $payload = ['success' => true, 'count' => 1],
        private readonly ?Throwable $throw = null,
        private readonly bool $includeSecret = false,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: $this->toolName,
            description: 'Test-only integration tool.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING'],
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
            confirmationHint: 'Confirm the intended calendar change.',
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability($this->capability);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $payload = $this->payload;
        if ($this->includeSecret) {
            $payload['access_token'] = 'SHOULD-NEVER-BE-LOGGED';
        }

        if ($this->fail) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'fake_failed',
                'access_token' => 'SHOULD-NEVER-BE-LOGGED',
            ]);
        }

        return ToolResult::success($call->id, $this->name(), $payload);
    }
}
