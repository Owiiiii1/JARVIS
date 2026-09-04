<?php

namespace App\Services\Tools;

use App\Enums\ToolConfirmationDecision;
use App\Enums\ToolExecutionLogStatus;
use App\Models\IntegrationAccount;
use App\Models\ToolExecutionLog;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ToolExecutionService
{
    public function __construct(
        private readonly ToolConfirmationPolicy $policy,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function run(ToolRegistry $registry, ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $startedAt = microtime(true);
        $tool = $registry->resolve($call->name);
        $meta = $tool?->meta();
        $account = $this->resolveAccount($context, $meta);

        if ($tool === null || ! $tool->isAvailable($context)) {
            $result = ToolResult::failure($call->id, $call->name, [
                'success' => false,
                'error' => 'tool_not_available',
            ]);
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                ToolExecutionLogStatus::Denied,
                ToolConfirmationDecision::Denied,
                $result,
                $startedAt,
            );

            return $result;
        }

        $decision = $this->policy->decide($tool, $context, $call);

        if ($decision === ToolConfirmationDecision::Denied) {
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => 'tool_denied',
            ]);
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                ToolExecutionLogStatus::Denied,
                $decision,
                $result,
                $startedAt,
            );

            return $result;
        }

        if ($decision === ToolConfirmationDecision::ConfirmationRequired) {
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => 'confirmation_required',
                'message' => $meta?->confirmationHint ?? 'This action needs confirmation before it can run.',
            ]);
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                ToolExecutionLogStatus::ConfirmationRequired,
                $decision,
                $result,
                $startedAt,
            );

            return $result;
        }

        try {
            $result = $tool->execute($call, $context);
        } catch (IntegrationException $exception) {
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => $exception->error,
                'retryable' => $exception->retryable,
            ]);
        } catch (Throwable $exception) {
            Log::warning('tool execution failed', [
                'tool' => $tool->name(),
                'user_id' => $context->user->id,
                'provider' => $meta?->provider,
                'error_code' => 'tool_failed',
            ]);
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => 'tool_failed',
            ]);
        }

        $status = $result->success
            ? ToolExecutionLogStatus::Succeeded
            : ToolExecutionLogStatus::Failed;

        $this->persistLog(
            $context,
            $call,
            $meta,
            $account,
            $status,
            $decision,
            $result,
            $startedAt,
        );

        if ($account !== null) {
            if ($result->success) {
                $this->accounts->recordSuccess($account);
            } else {
                $this->accounts->recordError($account, (string) ($result->payload['error'] ?? 'tool_failed'));
            }
        }

        return $result;
    }

    private function resolveAccount(ToolExecutionContext $context, ?ToolMeta $meta): ?IntegrationAccount
    {
        if ($meta?->provider === null) {
            return null;
        }

        try {
            return $this->accounts->getActiveAccount($context->user, $meta->provider);
        } catch (IntegrationException) {
            return null;
        }
    }

    private function persistLog(
        ToolExecutionContext $context,
        ToolCall $call,
        ?ToolMeta $meta,
        ?IntegrationAccount $account,
        ToolExecutionLogStatus $status,
        ToolConfirmationDecision $decision,
        ToolResult $result,
        float $startedAt,
    ): void {
        $finishedAt = now();
        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        ToolExecutionLog::query()->create([
            'user_id' => $context->user->id,
            'conversation_id' => $context->conversation->id,
            'tool_name' => $call->name,
            'capability' => $meta?->capability,
            'provider' => $meta?->provider,
            'integration_account_id' => $account?->id,
            'status' => $status,
            'confirmation_state' => $decision,
            'duration_ms' => $duration,
            'error_code' => $result->success ? null : (string) ($result->payload['error'] ?? 'tool_failed'),
            'metadata' => $this->safeMetadata($result),
            'started_at' => now()->subMilliseconds(max(0, $duration)),
            'finished_at' => $finishedAt,
        ]);

        Log::info('tool executed', [
            'tool' => $call->name,
            'user_id' => $context->user->id,
            'provider' => $meta?->provider,
            'status' => $status->value,
            'duration_ms' => $duration,
            'account_id' => $account?->id,
            'error_code' => $result->success ? null : (string) ($result->payload['error'] ?? 'tool_failed'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeMetadata(ToolResult $result): array
    {
        $payload = $result->payload;
        $metadata = [];

        if (isset($payload['error']) && is_string($payload['error'])) {
            $metadata['error'] = $payload['error'];
        }

        foreach (['count', 'result_count', 'groups_searched', 'topics_count', 'memories_count', 'summaries_count'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $metadata[$key] = (int) $payload[$key];
            }
        }

        if (isset($payload['groups']) && is_array($payload['groups'])) {
            $metadata['result_count'] = count($payload['groups']);
        }

        if (isset($payload['snippets']) && is_array($payload['snippets'])) {
            $metadata['result_count'] = count($payload['snippets']);
        }

        return $metadata;
    }
}
