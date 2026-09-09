<?php

namespace App\Services\Productivity;

final class ProductivityBriefPhrasing
{
    public static function isComplete(string $phrased, ?string $finishReason = null): bool
    {
        $phrased = trim($phrased);
        if ($phrased === '') {
            return false;
        }

        $reason = strtolower(str_replace(['-', ' '], '_', (string) $finishReason));
        if (in_array($reason, ['length', 'max_tokens', 'maxtokens'], true)) {
            return false;
        }

        return preg_match('/[,:;\-–—]$/u', $phrased) !== 1;
    }

    public static function isSubstantial(string $deterministic, string $phrased): bool
    {
        $phrased = trim($phrased);
        $deterministic = trim($deterministic);

        if ($phrased === '') {
            return false;
        }

        return mb_strlen($deterministic) < 80 || mb_strlen($phrased) >= 40;
    }
}
