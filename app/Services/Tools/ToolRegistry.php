<?php

namespace App\Services\Tools;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use Illuminate\Support\Facades\Log;

final class ToolRegistry
{
    /**
     * @param  list<JarvisTool>  $tools
     */
    public function __construct(
        private readonly array $tools,
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
        $startedAt = microtime(true);
        $tool = $this->find($call->name);

        if ($tool === null || ! $tool->isAvailable($context)) {
            Log::info('tool denied', [
                'tool' => $call->name,
                'user_id' => $context->user->id,
                'error_class' => 'tool_not_available',
            ]);

            return ToolResult::failure($call->id, $call->name, [
                'success' => false,
                'error' => 'tool_not_available',
            ]);
        }

        $result = $tool->execute($call, $context);

        Log::info('tool executed', [
            'tool' => $call->name,
            'user_id' => $context->user->id,
            'success' => $result->success,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_class' => $result->success ? null : (string) ($result->payload['error'] ?? 'tool_failed'),
            'reminder_id' => $result->payload['reminder_id'] ?? null,
            'project_id' => $result->payload['project']['id'] ?? null,
            'topics_count' => isset($result->payload['topics']) && is_array($result->payload['topics']) ? count($result->payload['topics']) : null,
            'memories_count' => isset($result->payload['memories']) && is_array($result->payload['memories']) ? count($result->payload['memories']) : null,
            'summaries_count' => isset($result->payload['conversation_summaries']) && is_array($result->payload['conversation_summaries']) ? count($result->payload['conversation_summaries']) : null,
        ]);

        return $result;
    }

    private function find(string $name): ?JarvisTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
