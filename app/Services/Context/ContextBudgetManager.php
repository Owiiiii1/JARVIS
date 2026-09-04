<?php

namespace App\Services\Context;

use App\Models\AiRoleSetting;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiContentPart;

final class ContextBudgetManager
{
    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly AiModelContextPolicy $models,
    ) {}

    /**
     * @return array{system_prompt: string, messages: list<AiChatMessage>, diagnostics: array<string, mixed>}
     */
    public function assemble(AiRoleSetting $configuration, ContextSlices $slices): array
    {
        $policy = $this->models->for($configuration);
        $inputBudget = $policy['input_budget'];
        $trimmed = [
            'recent_messages' => 0,
            'summary' => 0,
            'memories' => 0,
            'cross_chat' => 0,
            'general_prompt' => 0,
            'assistant_identity' => 0,
            'projects' => 0,
            'current_turn' => 0,
        ];

        $platform = $this->clip($slices->platformPrompt, (int) config('context_budget.platform_prompt', 2800));
        $identity = $this->clipNullable($slices->assistantIdentity, (int) config('context_budget.assistant_identity', 600), $trimmed, 'assistant_identity');
        $general = $this->clipNullable($slices->generalPrompt, (int) config('context_budget.general_prompt', 800), $trimmed, 'general_prompt');
        $event = $slices->applicationEvent;
        $summary = $this->clipNullable($slices->currentSummary, (int) config('context_budget.current_conversation_summary', 1200), $trimmed, 'summary');
        $profile = $this->clipNullable($slices->profile, (int) config('context_budget.personal_memory', 800));
        $memories = $this->clipLines($slices->memoryLines, (int) config('context_budget.personal_memory', 800), $trimmed, 'memories');
        $cross = $this->clipLines($slices->crossChatLines, (int) config('context_budget.cross_chat_summaries', 800), $trimmed, 'cross_chat');
        $projects = $this->clipNullable($slices->projectsBlock, (int) config('context_budget.projects', 400), $trimmed, 'projects');
        $messages = $this->boundRecent($slices->recentMessages, $slices->lastIsCurrentTurn, (int) config('context_budget.recent_messages', 6000), $trimmed);

        $overflow = false;

        while ($this->estimateRequest($platform, $this->systemFrom(
            $platform,
            $identity,
            $general,
            $event,
            $summary,
            $profile,
            $memories,
            $cross,
            $projects,
        ), $messages) > $inputBudget) {
            $overflow = true;

            if ($cross !== []) {
                array_pop($cross);
                $trimmed['cross_chat']++;

                continue;
            }
            if ($memories !== []) {
                array_pop($memories);
                $trimmed['memories']++;

                continue;
            }
            if ($projects !== null) {
                $projects = null;
                $trimmed['projects']++;

                continue;
            }
            if ($profile !== null) {
                $profile = null;
                $trimmed['memories']++;

                continue;
            }
            if ($summary !== null) {
                $summary = $this->estimator->clipToTokens($summary, max(120, intdiv($this->estimator->estimateText($summary), 2)));
                if ($this->estimator->estimateText($summary) < 160) {
                    $summary = null;
                }
                $trimmed['summary']++;

                continue;
            }
            if ($general !== null) {
                $general = $this->estimator->clipToTokens($general, max(80, intdiv($this->estimator->estimateText($general), 2)));
                if ($this->estimator->estimateText($general) < 100) {
                    $general = null;
                }
                $trimmed['general_prompt']++;

                continue;
            }
            if ($identity !== null) {
                $identity = $this->estimator->clipToTokens($identity, max(80, intdiv($this->estimator->estimateText($identity), 2)));
                if ($this->estimator->estimateText($identity) < 80) {
                    $identity = null;
                }
                $trimmed['assistant_identity']++;

                continue;
            }

            $minimum = max(1, (int) config('context_budget.emergency_minimum_recent', 2));
            $keep = $slices->lastIsCurrentTurn ? max($minimum, 1) : $minimum;
            if (count($messages) > $keep) {
                array_shift($messages);
                $trimmed['recent_messages']++;

                continue;
            }

            break;
        }

        $system = $this->systemFrom($platform, $identity, $general, $event, $summary, $profile, $memories, $cross, $projects);
        $estimated = $this->estimateRequest($platform, $system, $messages);

        if ($estimated > $inputBudget && $messages !== []) {
            $overflow = true;
            $last = $messages[array_key_last($messages)];
            if (! $last->hasImageParts()) {
                $room = max(200, $inputBudget - $this->estimateRequest($platform, $system, array_slice($messages, 0, -1)));
                $messages[array_key_last($messages)] = new AiChatMessage($last->role, $this->estimator->clipToTokens($last->content, $room));
                $trimmed['current_turn']++;
                $estimated = $this->estimateRequest($platform, $system, $messages);
            }
        }

        $diagnostics = $this->diagnostics(
            $configuration,
            $policy,
            $estimated,
            $overflow,
            $trimmed,
            $messages,
            $summary,
            $memories,
            $cross,
            $projects,
            $general,
            $identity,
        );

        return [
            'system_prompt' => $system,
            'messages' => $messages,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  list<AiChatMessage>  $messages
     * @param  array<string, mixed>  $prior
     * @return array{system_prompt: string, messages: list<AiChatMessage>, diagnostics: array<string, mixed>}
     */
    public function enforceRequest(string $systemPrompt, array $messages, AiRoleSetting $configuration, array $prior = []): array
    {
        $policy = $this->models->for($configuration);
        $inputBudget = $policy['input_budget'];
        $overflow = false;
        $trimmedTools = 0;

        while ($this->estimateRequest($systemPrompt, $systemPrompt, $messages) > $inputBudget) {
            $overflow = true;
            $index = $this->oldestToolMessageIndex($messages);
            if ($index === null) {
                break;
            }

            $message = $messages[$index];
            $payload = is_array($message->toolResponse) ? $message->toolResponse : [];
            $compact = $this->compactToolPayload($payload);
            $messages[$index] = new AiChatMessage(
                role: $message->role,
                content: json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                toolResponse: $compact,
            );
            $trimmedTools++;

            if ($this->estimateRequest($systemPrompt, $systemPrompt, $messages) > $inputBudget) {
                $messages[$index] = new AiChatMessage(
                    role: $message->role,
                    content: json_encode([
                        'success' => (bool) ($payload['success'] ?? false),
                        'error' => 'tool_context_budget_exceeded',
                        'truncated' => true,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    toolCallId: $message->toolCallId,
                    toolName: $message->toolName,
                    toolResponse: [
                        'success' => false,
                        'error' => 'tool_context_budget_exceeded',
                        'truncated' => true,
                    ],
                );
            } else {
                break;
            }

            if ($trimmedTools > 40) {
                break;
            }
        }

        $estimated = $this->estimateRequest($systemPrompt, $systemPrompt, $messages);
        $diagnostics = array_merge($prior, [
            'estimated_input_tokens' => $estimated,
            'output_reserve' => $policy['reserved_output_tokens'],
            'max_context_tokens' => $policy['max_context_tokens'],
            'input_budget' => $inputBudget,
            'utilization_percent' => $inputBudget > 0 ? (int) min(100, round(($estimated / $inputBudget) * 100)) : 0,
            'overflow_prevented' => $overflow || (bool) ($prior['overflow_prevented'] ?? false),
            'model' => (string) $configuration->model,
            'provider' => (string) $configuration->provider,
            'configuration' => $configuration->roleKey()->value,
        ]);
        $diagnostics['trimmed'] = array_merge((array) ($prior['trimmed'] ?? []), [
            'tool_results' => ((int) (($prior['trimmed']['tool_results'] ?? 0)) + $trimmedTools),
        ]);

        return [
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  list<string>  $memories
     * @param  list<string>  $cross
     * @param  list<AiChatMessage>  $messages
     * @param  array<string, int>  $trimmed
     * @return array<string, mixed>
     */
    private function diagnostics(
        AiRoleSetting $configuration,
        array $policy,
        int $estimated,
        bool $overflow,
        array $trimmed,
        array $messages,
        ?string $summary,
        array $memories,
        array $cross,
        ?string $projects,
        ?string $general,
        ?string $identity,
    ): array {
        $inputBudget = $policy['input_budget'];

        return [
            'configuration' => $configuration->roleKey()->value,
            'provider' => (string) $configuration->provider,
            'model' => (string) $configuration->model,
            'estimated_input_tokens' => $estimated,
            'output_reserve' => $policy['reserved_output_tokens'],
            'max_context_tokens' => $policy['max_context_tokens'],
            'input_budget' => $inputBudget,
            'utilization_percent' => $inputBudget > 0 ? (int) min(100, round(($estimated / $inputBudget) * 100)) : 0,
            'overflow_prevented' => $overflow,
            'sources' => [
                'recent_messages' => ['count' => count($messages), 'tokens' => $this->estimateMessages($messages)],
                'summary' => ['count' => $summary === null ? 0 : 1, 'tokens' => $this->estimator->estimateText((string) $summary)],
                'memories' => ['count' => count($memories), 'tokens' => $this->estimator->estimateText(implode("\n", $memories))],
                'cross_chat' => ['count' => count($cross), 'tokens' => $this->estimator->estimateText(implode("\n", $cross))],
                'projects' => ['count' => $projects === null ? 0 : 1, 'tokens' => $this->estimator->estimateText((string) $projects)],
                'general_prompt' => ['count' => $general === null ? 0 : 1, 'tokens' => $this->estimator->estimateText((string) $general)],
                'assistant_identity' => ['count' => $identity === null ? 0 : 1, 'tokens' => $this->estimator->estimateText((string) $identity)],
                'current_files' => ['count' => 0, 'tokens' => 0],
                'tool_results' => ['count' => 0, 'tokens' => 0],
            ],
            'trimmed' => $trimmed,
        ];
    }

    /**
     * @param  list<string>  $memories
     * @param  list<string>  $cross
     */
    private function systemFrom(
        string $platform,
        ?string $identity,
        ?string $general,
        ?string $event,
        ?string $summary,
        ?string $profile,
        array $memories,
        array $cross,
        ?string $projects,
    ): string {
        $sections = [trim($platform)];

        if ($identity !== null && $identity !== '') {
            $sections[] = $identity;
        }

        if ($general !== null && $general !== '') {
            $sections[] = "User General Prompt:\n".$general;
        }

        if ($memories !== []) {
            $sections[] = "Relevant personal memory:\n".implode("\n", $memories);
        }

        if ($profile !== null && $profile !== '') {
            $sections[] = "User profile:\n".$profile;
        }

        if ($cross !== []) {
            $sections[] = "Relevant summaries from other chats of this user:\n".implode("\n", $cross);
        }

        if ($summary !== null && $summary !== '') {
            $sections[] = "Current conversation summary:\n".$summary;
        }

        if ($projects !== null && $projects !== '') {
            $sections[] = $projects;
        }

        if ($event !== null && trim($event) !== '') {
            $sections[] = "Application event:\n".trim($event);
        }

        return trim(implode("\n\n", array_filter($sections)));
    }

    /**
     * @param  list<AiChatMessage>  $messages
     */
    private function estimateRequest(string $ignoredPlatform, string $system, array $messages): int
    {
        return $this->estimator->estimateText($system) + $this->estimateMessages($messages);
    }

    /**
     * @param  list<AiChatMessage>  $messages
     */
    private function estimateMessages(array $messages): int
    {
        $total = 0;
        $imageTokens = max(64, (int) config('context_budget.image_tokens', 768));

        foreach ($messages as $message) {
            $total += 6;
            $total += $this->estimator->estimateText($message->content);
            foreach ($message->contentParts as $part) {
                if ($part instanceof AiContentPart && $part->isImage()) {
                    $total += $imageTokens;
                }
            }
        }

        return $total;
    }

    /**
     * @param  list<AiChatMessage>  $messages
     * @param  array<string, int>  $trimmed
     * @return list<AiChatMessage>
     */
    private function boundRecent(array $messages, bool $lastIsCurrentTurn, int $budget, array &$trimmed): array
    {
        if ($messages === []) {
            return [];
        }

        $currentTokens = (int) config('context_budget.current_turn', 4000);
        $selected = [];
        $used = 0;
        $count = count($messages);

        for ($i = $count - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            $isCurrent = $lastIsCurrentTurn && $i === $count - 1;
            $cost = $this->estimateMessages([$message]);
            $cap = $isCurrent ? $currentTokens : $budget;

            if ($isCurrent && $cost > $currentTokens && ! $message->hasImageParts()) {
                $message = new AiChatMessage($message->role, $this->estimator->clipToTokens($message->content, $currentTokens));
                $cost = $this->estimateMessages([$message]);
                $trimmed['current_turn']++;
            }

            if (! $isCurrent && $used + $cost > $budget && $selected !== []) {
                $trimmed['recent_messages']++;

                continue;
            }

            array_unshift($selected, $message);
            $used += $cost;
        }

        $minimum = max(1, (int) config('context_budget.emergency_minimum_recent', 2));
        if (count($selected) < $minimum) {
            $selected = array_slice($messages, -$minimum);
        }

        return $selected;
    }

    /**
     * @param  array<string, int>  $trimmed
     * @return list<string>
     */
    private function clipLines(array $lines, int $budget, array &$trimmed, string $key): array
    {
        $kept = [];
        $used = 0;

        foreach ($lines as $line) {
            $cost = $this->estimator->estimateText($line);
            if ($used + $cost > $budget && $kept !== []) {
                $trimmed[$key] = ($trimmed[$key] ?? 0) + 1;

                continue;
            }
            if ($cost > $budget) {
                $line = $this->estimator->clipToTokens($line, $budget);
                $trimmed[$key] = ($trimmed[$key] ?? 0) + 1;
                $cost = $this->estimator->estimateText($line);
            }
            $kept[] = $line;
            $used += $cost;
        }

        return $kept;
    }

    /**
     * @param  array<string, int>  $trimmed
     */
    private function clipNullable(?string $text, int $budget, array &$trimmed = [], ?string $key = null): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $clipped = $this->clip($text, $budget);
        if ($key !== null && $clipped !== $text) {
            $trimmed[$key] = ($trimmed[$key] ?? 0) + 1;
        }

        return $clipped;
    }

    private function clip(string $text, int $budget): string
    {
        return $this->estimator->clipToTokens(trim($text), max(1, $budget));
    }

    /**
     * @param  list<AiChatMessage>  $messages
     */
    private function oldestToolMessageIndex(array $messages): ?int
    {
        foreach ($messages as $index => $message) {
            if ($message->role === 'tool' || $message->toolResponse !== null) {
                $payload = $message->toolResponse ?? [];
                if (($payload['error'] ?? null) === 'tool_context_budget_exceeded') {
                    continue;
                }

                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactToolPayload(array $payload): array
    {
        $keep = ['success', 'error', 'truncated', 'retryable', 'id', 'file_id', 'confirmation_id', 'count', 'query', 'url', 'requested_url', 'final_url', 'title', 'domain', 'published_at', 'fetched_at', 'char_count', 'provider'];
        $compact = [];

        foreach ($keep as $key) {
            if (array_key_exists($key, $payload)) {
                $compact[$key] = $payload[$key];
            }
        }

        $compact['truncated'] = true;
        if (! isset($compact['success'])) {
            $compact['success'] = (bool) ($payload['success'] ?? false);
        }

        return $compact;
    }
}
