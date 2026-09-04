<?php

namespace App\Services\Context;

use Illuminate\Support\Facades\Log;
use Throwable;

final class ContextDiagnosticsLogger
{
    /**
     * @param  array<string, mixed>  $diagnostics
     */
    public function log(?int $userId, ?int $conversationId, array $diagnostics): void
    {
        try {
            Log::info('context budget', [
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'configuration' => $diagnostics['configuration'] ?? null,
                'provider' => $diagnostics['provider'] ?? null,
                'model' => $diagnostics['model'] ?? null,
                'estimated_input_tokens' => $diagnostics['estimated_input_tokens'] ?? null,
                'output_reserve' => $diagnostics['output_reserve'] ?? null,
                'max_context_tokens' => $diagnostics['max_context_tokens'] ?? null,
                'input_budget' => $diagnostics['input_budget'] ?? null,
                'utilization_percent' => $diagnostics['utilization_percent'] ?? null,
                'overflow_prevented' => (bool) ($diagnostics['overflow_prevented'] ?? false),
                'sources' => $diagnostics['sources'] ?? [],
                'trimmed' => $diagnostics['trimmed'] ?? [],
            ]);
        } catch (Throwable) {
        }
    }
}
