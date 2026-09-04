<?php

namespace App\Services\Tools;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;

final class ToolRegistry
{
    /**
     * @param  list<JarvisTool>  $tools
     */
    public function __construct(
        private array $tools,
    ) {}

    /**
     * @return list<ToolDefinition>
     */
    public function definitionsFor(ToolExecutionContext $context): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if ($tool->isAvailable($context)) {
                $definitions[] = $tool->definition();
            }
        }

        return $definitions;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        return app(ToolExecutionService::class)->run($this, $call, $context);
    }

    public function resolve(string $name): ?JarvisTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    public function register(JarvisTool $tool): void
    {
        $this->tools[] = $tool;
    }
}
