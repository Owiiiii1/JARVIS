<?php

namespace App\Services\Context;

final class TokenEstimator
{
    public function estimateText(?string $text): int
    {
        $text = (string) $text;

        if ($text === '') {
            return 0;
        }

        $chars = mb_strlen($text);
        $words = preg_match_all('/\S+/u', $text) ?: 0;
        $cjk = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) ?: 0;
        $latin = max(0, $chars - $cjk);

        return max(1, (int) ceil(($cjk * 1.25) + ($latin / 2.8) + ($words * 0.2) + 8));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    public function estimateJson(array $value): int
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return $this->estimateText($encoded);
    }

    public function clipToTokens(string $text, int $maxTokens): string
    {
        if ($maxTokens <= 0) {
            return '';
        }

        if ($this->estimateText($text) <= $maxTokens) {
            return $text;
        }

        $low = 0;
        $high = mb_strlen($text);
        $best = '';

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $slice = mb_substr($text, 0, $mid);
            if ($this->estimateText($slice) <= $maxTokens) {
                $best = $slice;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return rtrim($best);
    }
}
