<?php

namespace App\Services\Context;

use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\WebResearch\FetchWebPageTool;
use App\Services\Tools\WebResearch\SearchWebTool;

final class ToolResultBudgetManager
{
    /**
     * @var list<string>
     */
    private const CONTENT_KEYS = [
        'content', 'excerpt', 'body', 'text', 'html', 'diff', 'patch', 'raw', 'chunks',
    ];

    /**
     * @var list<string>
     */
    private const LIST_KEYS = [
        'results', 'snippets', 'messages', 'events', 'files', 'commits', 'issues',
        'pull_requests', 'comments', 'labels', 'repositories', 'branches',
        'workflow_runs', 'calendars', 'groups', 'chunks',
    ];

    public function __construct(
        private readonly TokenEstimator $estimator,
    ) {}

    public function apply(ToolResult $result, TurnBudgetTracker $tracker): ToolResult
    {
        $family = $this->family($result->name);
        $totalBudget = max(200, (int) config('context_budget.tool_results', 6000));
        $familyBudget = max(200, (int) config('context_budget.'.$family.'_results', $totalBudget));
        $familyUsed = $this->familyUsed($tracker, $family);
        $remaining = min(
            max(0, $totalBudget - $tracker->toolResultTokens),
            max(0, $familyBudget - $familyUsed),
        );

        $tokens = $this->estimator->estimateJson($result->payload);

        if ($tokens <= $remaining) {
            $this->charge($tracker, $family, $tokens);

            return $result;
        }

        if ($remaining < 80) {
            $payload = [
                'success' => false,
                'error' => 'tool_context_budget_exceeded',
                'truncated' => true,
            ];
            $this->copyMeta($result->payload, $payload);
            $charged = $this->estimator->estimateJson($payload);
            $this->charge($tracker, $family, $charged);

            return ToolResult::failure($result->callId, $result->name, $payload);
        }

        $payload = $this->trimPayload($result->payload, $remaining);
        $charged = $this->estimator->estimateJson($payload);
        $this->charge($tracker, $family, $charged);

        if ($result->success) {
            return ToolResult::success($result->callId, $result->name, $payload);
        }

        return ToolResult::failure($result->callId, $result->name, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function trimPayload(array $payload, int $remaining): array
    {
        $payload['truncated'] = true;

        foreach (self::CONTENT_KEYS as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                continue;
            }
            $payload[$key] = $this->estimator->clipToTokens($payload[$key], max(40, intdiv($remaining, 2)));
        }

        foreach (self::LIST_KEYS as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }

            $items = $payload[$key];
            while ($items !== [] && $this->estimator->estimateJson($payload) > $remaining) {
                array_pop($items);
                $payload[$key] = $items;
                $payload['truncated'] = true;
            }

            foreach ($payload[$key] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['snippet', 'content', 'excerpt', 'body', 'text', 'diff'] as $field) {
                    if (isset($item[$field]) && is_string($item[$field])) {
                        $item[$field] = $this->estimator->clipToTokens($item[$field], 80);
                    }
                }
                $payload[$key][$index] = $item;
            }
        }

        if ($this->estimator->estimateJson($payload) > $remaining) {
            $compact = [
                'success' => (bool) ($payload['success'] ?? false),
                'error' => (string) ($payload['error'] ?? 'tool_context_budget_exceeded'),
                'truncated' => true,
            ];
            $this->copyMeta($payload, $compact);
            if (($payload['success'] ?? false) === true && ($payload['error'] ?? null) === null) {
                unset($compact['error']);
            }

            return $compact;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function copyMeta(array $from, array &$to): void
    {
        foreach (['id', 'file_id', 'count', 'query', 'url', 'requested_url', 'final_url', 'title', 'domain', 'provider', 'confirmation_id'] as $key) {
            if (array_key_exists($key, $from)) {
                $to[$key] = $from[$key];
            }
        }
    }

    private function family(string $toolName): string
    {
        if (in_array($toolName, [SearchWebTool::NAME, FetchWebPageTool::NAME], true) || str_starts_with($toolName, 'search_web') || str_starts_with($toolName, 'fetch_web')) {
            return 'web';
        }
        if (str_contains($toolName, 'gmail')) {
            return 'gmail';
        }
        if (str_contains($toolName, 'github')) {
            return 'github';
        }
        if (str_contains($toolName, 'storage')) {
            return 'storage';
        }
        if (str_contains($toolName, 'group')) {
            return 'group';
        }

        return 'tool';
    }

    private function familyUsed(TurnBudgetTracker $tracker, string $family): int
    {
        return match ($family) {
            'web' => $tracker->webResultTokens,
            'gmail' => $tracker->gmailResultTokens,
            'github' => $tracker->githubResultTokens,
            'group' => $tracker->groupResultTokens,
            'storage' => $tracker->storageResultTokens,
            default => 0,
        };
    }

    private function charge(TurnBudgetTracker $tracker, string $family, int $tokens): void
    {
        $tracker->toolResultTokens += $tokens;

        match ($family) {
            'web' => $tracker->webResultTokens += $tokens,
            'gmail' => $tracker->gmailResultTokens += $tokens,
            'github' => $tracker->githubResultTokens += $tokens,
            'group' => $tracker->groupResultTokens += $tokens,
            'storage' => $tracker->storageResultTokens += $tokens,
            default => null,
        };
    }
}
