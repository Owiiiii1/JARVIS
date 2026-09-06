<?php

namespace App\Services\ConversationIntelligence;

use App\Enums\TopicContinuityMode;

final class TopicContinuityDetector
{
    /**
     * @param  list<string>  $recentUserAndAssistantTexts  newest last
     * @param  list<string>  $knownTopics
     */
    public function detect(
        string $currentText,
        array $recentUserAndAssistantTexts,
        array $knownTopics = [],
        ?string $currentTopic = null,
    ): TopicContinuityMode {
        $normalized = $this->normalize($currentText);

        if ($normalized === '') {
            return TopicContinuityMode::Continue;
        }

        if ($this->isReturn($normalized, $knownTopics, $currentTopic)) {
            return TopicContinuityMode::Return;
        }

        if ($this->isSubtopic($normalized)) {
            return TopicContinuityMode::Subtopic;
        }

        if ($this->isSwitch($normalized, $recentUserAndAssistantTexts, $currentTopic)) {
            return TopicContinuityMode::Switch;
        }

        return TopicContinuityMode::Continue;
    }

    public function returnedTopicName(string $currentText, array $knownTopics, ?string $currentTopic = null): ?string
    {
        $normalized = $this->normalize($currentText);

        foreach ($knownTopics as $topic) {
            $topicNorm = $this->normalize((string) $topic);

            if ($topicNorm === '' || $topicNorm === $this->normalize((string) $currentTopic)) {
                continue;
            }

            if (str_contains($normalized, $topicNorm)) {
                return trim((string) $topic);
            }
        }

        if (preg_match('/верн(?:е|ё)мся\s+к(?:\s+тому,?\s+что\s+обсуждали\s+про)?\s+(.+)$/u', $normalized, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        if (preg_match('/продолжим\s+с\s+(.+)$/u', $normalized, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return null;
    }

    private function isReturn(string $normalized, array $knownTopics, ?string $currentTopic): bool
    {
        if (preg_match('/верн(?:е|ё)мся|что ты говорил|как ты говорил|back to|let\'s go back|продолжим с/u', $normalized) === 1) {
            return true;
        }

        $returned = $this->returnedTopicName($normalized, $knownTopics, $currentTopic);

        return $returned !== null && $returned !== '';
    }

    private function isSubtopic(string $normalized): bool
    {
        return (bool) preg_match('/^(а |и ещё|еще |а по |а если |и потом|ещё добавь|еще добавь|а с этим|ну а )/u', $normalized);
    }

    /**
     * @param  list<string>  $recentUserAndAssistantTexts
     */
    private function isSwitch(string $normalized, array $recentUserAndAssistantTexts, ?string $currentTopic): bool
    {
        if (mb_strlen($normalized) < 24) {
            return false;
        }

        if (preg_match('/^(а |и |ну |так |ещё |еще )/u', $normalized) === 1) {
            return false;
        }

        $prior = $this->normalize(implode(' ', array_slice($recentUserAndAssistantTexts, -4)));

        if ($prior === '') {
            return false;
        }

        if ($currentTopic !== null && $currentTopic !== '' && str_contains($normalized, $this->normalize($currentTopic))) {
            return false;
        }

        $currentTokens = $this->tokens($normalized);
        $priorTokens = $this->tokens($prior);
        $overlap = count(array_intersect($currentTokens, $priorTokens));

        return $overlap === 0 && count($currentTokens) >= 4;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $stop = ['это', 'эта', 'этот', 'эти', 'там', 'тут', 'про', 'для', 'как', 'что', 'если', 'потом', 'надо', 'нужно', 'можно', 'the', 'and', 'for', 'with'];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < 3 || in_array($part, $stop, true)) {
                continue;
            }

            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
