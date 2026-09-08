<?php

namespace App\Services\Ai;

final class AgentTurnTrace
{
    /**
     * @param  list<array{name: string, success: bool, new_information: bool, reused: bool}>  $toolCalls
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly ?int $turnId,
        public int $toolRoundCount = 0,
        public array $toolCalls = [],
        public bool $repetitionDetected = false,
        public bool $forcedFinalSynthesis = false,
        public int $providerRetryCount = 0,
        public bool $partialRecovery = false,
        public string $finalOutcome = 'answer',
        public bool $toolsDisabledForTurn = false,
    ) {}

    public static function start(int $userId, int $conversationId, ?int $turnId): self
    {
        return new self(
            userId: $userId,
            conversationId: $conversationId,
            turnId: $turnId,
        );
    }

    public function recordTool(string $name, bool $success, bool $newInformation, bool $reused): void
    {
        $this->toolCalls[] = [
            'name' => $name,
            'success' => $success,
            'new_information' => $newInformation,
            'reused' => $reused,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'event' => 'agent_turn',
            'turn_id' => $this->turnId,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'tool_round_count' => $this->toolRoundCount,
            'tool_calls' => array_map(
                static fn (array $call): array => [
                    'name' => $call['name'],
                    'success' => $call['success'],
                    'new_information' => $call['new_information'],
                    'reused' => $call['reused'],
                ],
                $this->toolCalls,
            ),
            'repetition_detected' => $this->repetitionDetected,
            'forced_final_synthesis' => $this->forcedFinalSynthesis,
            'provider_retry_count' => $this->providerRetryCount,
            'partial_recovery' => $this->partialRecovery,
            'final_outcome' => $this->finalOutcome,
            'tools_disabled_for_turn' => $this->toolsDisabledForTurn,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'tool_round_count' => $this->toolRoundCount,
            'repetition_detected' => $this->repetitionDetected,
            'forced_final_synthesis' => $this->forcedFinalSynthesis,
            'provider_retry_count' => $this->providerRetryCount,
            'partial_recovery' => $this->partialRecovery,
            'final_outcome' => $this->finalOutcome,
        ];
    }
}
