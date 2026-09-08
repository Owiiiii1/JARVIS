<?php

namespace App\Services\Ai;

use App\Enums\AsyncFailureCategory;
use App\Models\AiRoleSetting;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiEmptyResponseException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Context\ContextBudgetManager;
use App\Services\Context\ToolResultBudgetManager;
use App\Services\Reliability\AsyncFailureClassifier;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

final class AgentToolLoop
{
    public const SYNTHESIS_INSTRUCTION = 'Do not call tools. Answer the user using the information already collected this turn. If some sources succeeded and others failed, answer from the successful ones and mention the limitation in plain language. Partial answers are useful. Do not mention tool names, error codes, stack traces, or internal identifiers.';

    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly ToolRegistry $tools,
        private readonly ContextBudgetManager $contextBudgets,
        private readonly ToolResultBudgetManager $toolResultBudgets,
        private readonly AiSafetyResponseService $safetyResponses,
        private readonly AsyncFailureClassifier $failures = new AsyncFailureClassifier,
    ) {}

    /**
     * @param  list<AiChatMessage>  $messages
     * @param  list<ToolDefinition>  $tools
     * @param  list<ToolResult>  $toolResults
     * @param  array<string, mixed>  $diagnostics
     */
    public function run(
        AiRoleSetting $configuration,
        string $systemPrompt,
        array $messages,
        array $tools,
        ToolExecutionContext $toolContext,
        AgentTurnTrace $trace,
        array &$toolResults,
        array &$diagnostics,
        ?string $userText = null,
    ): AiChatResponse {
        $rounds = 0;
        $maxRounds = max(1, (int) config('context_budget.max_tool_rounds', 8));
        $noProgressLimit = max(1, (int) config('context_budget.no_progress_tool_rounds', 2));
        $progress = new ToolLoopProgress;
        $parameters = is_array($configuration->parameters) ? $configuration->parameters : [];

        while (true) {
            $enforced = $this->contextBudgets->enforceRequest($systemPrompt, $messages, $configuration, $diagnostics);
            $systemPrompt = $enforced['system_prompt'];
            $messages = $enforced['messages'];
            $diagnostics = $enforced['diagnostics'];

            try {
                $response = $this->chatWithRetries(
                    $configuration,
                    $systemPrompt,
                    $messages,
                    $tools,
                    $parameters,
                    $trace,
                );
            } catch (AiSafetyException $exception) {
                try {
                    Log::warning('AI response blocked; retrying with safety guidance', [
                        'configuration' => $configuration->roleKey()->value,
                        'provider' => $configuration->provider,
                        'model' => $configuration->model,
                        'reason' => $exception->reason,
                    ]);

                    return $this->safetyResponses->retry(
                        $configuration,
                        $messages,
                        $parameters,
                    );
                } catch (Throwable) {
                    throw $exception;
                }
            }

            if (! $response->hasToolCalls()) {
                if (trim($response->text) === '') {
                    if ($this->hasUsefulResults($toolResults)) {
                        return $this->synthesize(
                            $configuration,
                            $systemPrompt,
                            $messages,
                            $parameters,
                            $toolResults,
                            $trace,
                            $userText,
                            $diagnostics,
                        );
                    }

                    throw new AiEmptyResponseException;
                }

                return $response;
            }

            if ($tools === []) {
                if (trim($response->text) !== '') {
                    return $response;
                }

                if ($this->hasUsefulResults($toolResults)) {
                    return $this->synthesize(
                        $configuration,
                        $systemPrompt,
                        $messages,
                        $parameters,
                        $toolResults,
                        $trace,
                        $userText,
                        $diagnostics,
                    );
                }

                throw new AiEmptyResponseException;
            }

            $hitRoundCap = $rounds >= $maxRounds;
            $hitNoProgress = $progress->shouldStop($noProgressLimit);

            if ($hitRoundCap || $hitNoProgress) {
                if ($hitNoProgress) {
                    $trace->repetitionDetected = true;
                }

                return $this->synthesize(
                    $configuration,
                    $systemPrompt,
                    $messages,
                    $parameters,
                    $toolResults,
                    $trace,
                    $userText,
                    $diagnostics,
                );
            }

            $nativeParts = is_array($response->metadata['native_parts'] ?? null)
                ? $response->metadata['native_parts']
                : [];

            $messages[] = AiChatMessage::assistantToolCalls(
                $response->toolCalls,
                $response->text,
                $nativeParts,
            );

            $round = [];

            foreach ($response->toolCalls as $call) {
                $observed = $this->executeCall($call, $toolContext, $progress, $trace);
                $toolResults[] = $observed['result'];
                $messages[] = AiChatMessage::toolResult($observed['result']);
                $round[] = $observed;
            }

            $progress->finishRound($round);
            $trace->repetitionDetected = $trace->repetitionDetected || $progress->repetitionDetected;
            $trace->partialRecovery = $this->isPartial($toolResults);
            $rounds++;
            $trace->toolRoundCount = $rounds;

            if ($progress->shouldStop($noProgressLimit)) {
                $trace->repetitionDetected = true;

                return $this->synthesize(
                    $configuration,
                    $systemPrompt,
                    $messages,
                    $parameters,
                    $toolResults,
                    $trace,
                    $userText,
                    $diagnostics,
                );
            }
        }
    }

    /**
     * @param  list<AiChatMessage>  $messages
     * @param  list<ToolDefinition>  $tools
     * @param  array<string, mixed>  $parameters
     */
    public function chatWithRetries(
        AiRoleSetting $configuration,
        string $systemPrompt,
        array $messages,
        array $tools,
        array $parameters,
        AgentTurnTrace $trace,
    ): AiChatResponse {
        $attempts = 1 + max(0, (int) config('context_budget.provider_retries', 2));
        $delayMs = max(0, (int) config('context_budget.provider_retry_delay_ms', 400));
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->gateway->chat($configuration, new AiChatRequest(
                    model: (string) $configuration->model,
                    systemPrompt: $systemPrompt,
                    messages: $attempt === 1 ? $messages : $this->cleanedMessages($messages),
                    parameters: $parameters,
                    tools: $tools,
                ));
            } catch (AiSafetyException $exception) {
                throw $exception;
            } catch (AiConfigurationException $exception) {
                throw $exception;
            } catch (AiProviderException $exception) {
                $last = $exception;

                if (! $this->shouldRetryProvider($exception, $attempt, $attempts)) {
                    throw $exception;
                }

                $trace->providerRetryCount++;

                try {
                    Log::warning('AI provider call failed; retrying', [
                        'configuration' => $configuration->roleKey()->value,
                        'provider' => $configuration->provider,
                        'model' => $configuration->model,
                        'attempt' => $attempt,
                        'max_attempts' => $attempts,
                        'error_class' => $exception::class,
                        'error_message' => $exception->getMessage(),
                    ]);
                } catch (Throwable) {
                }

                if ($delayMs > 0) {
                    Sleep::for($delayMs * $attempt)->milliseconds();
                }
            }
        }

        throw $last ?? new AiProviderException('AI provider request failed.');
    }

    /**
     * @param  list<AiChatMessage>  $messages
     * @param  list<ToolResult>  $toolResults
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $diagnostics
     */
    public function synthesize(
        AiRoleSetting $configuration,
        string $systemPrompt,
        array $messages,
        array $parameters,
        array $toolResults,
        AgentTurnTrace $trace,
        ?string $userText,
        array &$diagnostics,
    ): AiChatResponse {
        $trace->forcedFinalSynthesis = true;

        $instruction = self::SYNTHESIS_INSTRUCTION;
        $request = trim((string) $userText);

        if ($request !== '') {
            $instruction .= "\nCurrent user request: ".mb_substr($request, 0, 500);
        }

        $instruction .= $this->collectionStatus($toolResults);

        $synthesisPrompt = $systemPrompt."\n\n".$instruction;
        $synthesisMessages = $this->compactMessages($messages);
        $synthesisMessages[] = new AiChatMessage('user', $instruction);

        $attempts = 1 + max(0, (int) config('context_budget.final_synthesis_retries', 1));
        $lastEmpty = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $enforced = $this->contextBudgets->enforceRequest($synthesisPrompt, $synthesisMessages, $configuration, $diagnostics);
            $diagnostics = $enforced['diagnostics'];

            try {
                $final = $this->chatWithRetries(
                    $configuration,
                    $enforced['system_prompt'],
                    $enforced['messages'],
                    [],
                    $parameters,
                    $trace,
                );
            } catch (AiEmptyResponseException $exception) {
                $lastEmpty = $exception;

                continue;
            }

            if (trim($final->text) !== '') {
                return $final;
            }

            $lastEmpty = new AiEmptyResponseException;
        }

        throw $lastEmpty ?? new AiEmptyResponseException;
    }

    /**
     * @return array{call: ToolCall, result: ToolResult, reused: bool}
     */
    private function executeCall(
        ToolCall $call,
        ToolExecutionContext $toolContext,
        ToolLoopProgress $progress,
        AgentTurnTrace $trace,
    ): array {
        $cached = $progress->reusedResult($call);
        $reused = $cached !== null;

        if ($reused) {
            $result = new ToolResult(
                $call->id,
                $cached->name,
                $cached->success,
                $cached->payload,
            );
        } else {
            $result = $this->tools->execute($call, $toolContext);
            $progress->remember($call, $result);
        }

        $result = $this->toolResultBudgets->apply($result, $toolContext->budgets);
        $newInformation = ! $reused;
        $trace->recordTool($result->name, $result->success, $newInformation, $reused);

        return [
            'call' => $call,
            'result' => $result,
            'reused' => $reused,
        ];
    }

    private function shouldRetryProvider(AiProviderException $exception, int $attempt, int $maxAttempts): bool
    {
        if ($attempt >= $maxAttempts) {
            return false;
        }

        $failure = $this->failures->classify($exception);

        if (in_array($failure->category, [
            AsyncFailureCategory::ProviderAuth,
            AsyncFailureCategory::ProviderQuota,
            AsyncFailureCategory::ProviderSafety,
            AsyncFailureCategory::Serialization,
            AsyncFailureCategory::Validation,
        ], true)) {
            return false;
        }

        return $failure->retryable;
    }

    /**
     * @param  list<ToolResult>  $results
     */
    public function hasUsefulResults(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->success) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ToolResult>  $results
     */
    private function isPartial(array $results): bool
    {
        $ok = false;
        $fail = false;

        foreach ($results as $result) {
            if ($result->success) {
                $ok = true;
            } else {
                $fail = true;
            }
        }

        return $ok && $fail;
    }

    /**
     * @param  list<ToolResult>  $results
     */
    private function collectionStatus(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $lines = ['', 'Collected this turn:'];
        $seen = [];

        foreach ($results as $result) {
            $key = $result->name.'|'.($result->success ? 'ok' : 'fail');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $lines[] = $result->success
                ? '- '.$result->name.': succeeded'
                : '- '.$result->name.': could not be retrieved';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<AiChatMessage>  $messages
     * @return list<AiChatMessage>
     */
    private function compactMessages(array $messages): array
    {
        $seen = [];
        $compacted = [];

        foreach ($messages as $message) {
            if ($message->role !== 'tool') {
                $compacted[] = $message;

                continue;
            }

            $fingerprint = ToolCallFingerprint::payload(
                (string) $message->toolName,
                is_array($message->toolResponse) ? $message->toolResponse : [],
            );

            if (isset($seen[$fingerprint])) {
                $compacted[] = new AiChatMessage(
                    role: 'tool',
                    content: '{"duplicate":true}',
                    toolCallId: $message->toolCallId,
                    toolName: $message->toolName,
                    toolResponse: ['duplicate' => true],
                );

                continue;
            }

            $seen[$fingerprint] = true;
            $compacted[] = $message;
        }

        return $compacted;
    }

    /**
     * @param  list<AiChatMessage>  $messages
     * @return list<AiChatMessage>
     */
    private function cleanedMessages(array $messages): array
    {
        if (count($messages) <= 8) {
            return $this->compactMessages($messages);
        }

        $head = array_slice($messages, 0, 2);
        $tail = array_slice($messages, -6);

        return $this->compactMessages(array_merge($head, $tail));
    }
}
