<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;

final class ToolLoopProgress
{
    /** @var array<string, ToolResult> */
    private array $resultsByCall = [];

    /** @var array<string, true> */
    private array $seenResults = [];

    /** @var list<string> */
    private array $recentCallFingerprints = [];

    private int $noProgressStreak = 0;

    public bool $repetitionDetected = false;

    public function reusedResult(ToolCall $call): ?ToolResult
    {
        return $this->resultsByCall[ToolCallFingerprint::forCall($call)] ?? null;
    }

    public function remember(ToolCall $call, ToolResult $result): void
    {
        $this->resultsByCall[ToolCallFingerprint::forCall($call)] = $result;
    }

    /**
     * @param  list<array{call: ToolCall, result: ToolResult, reused: bool}>  $round
     */
    public function finishRound(array $round): void
    {
        $newInformation = false;

        foreach ($round as $item) {
            $callFingerprint = ToolCallFingerprint::forCall($item['call']);
            $this->recentCallFingerprints[] = $callFingerprint;
            $this->recentCallFingerprints = array_slice($this->recentCallFingerprints, -8);

            $resultFingerprint = ToolCallFingerprint::forResult($item['result']);
            $alreadySeen = isset($this->seenResults[$resultFingerprint]);
            $this->seenResults[$resultFingerprint] = true;

            if (! $item['reused'] && ! $alreadySeen) {
                $newInformation = true;
            }

            if ($item['reused'] || $alreadySeen) {
                $this->repetitionDetected = true;
            }
        }

        if ($newInformation) {
            $this->noProgressStreak = 0;

            return;
        }

        $this->noProgressStreak++;
        $this->repetitionDetected = true;
    }

    public function shouldStop(int $threshold): bool
    {
        if ($this->noProgressStreak >= max(1, $threshold)) {
            return true;
        }

        return $this->isAlternatingLoop();
    }

    public function noProgressStreak(): int
    {
        return $this->noProgressStreak;
    }

    private function isAlternatingLoop(): bool
    {
        $window = array_slice($this->recentCallFingerprints, -6);

        if (count($window) < 4 || $this->noProgressStreak < 2) {
            return false;
        }

        $unique = array_values(array_unique($window));

        return count($unique) >= 2 && count($unique) <= 3;
    }
}
