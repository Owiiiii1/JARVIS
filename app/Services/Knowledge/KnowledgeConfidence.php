<?php

namespace App\Services\Knowledge;

final class KnowledgeConfidence
{
    public static function highThreshold(): float
    {
        return (float) config('knowledge.confidence.high', 0.8);
    }

    public static function mediumThreshold(): float
    {
        return (float) config('knowledge.confidence.medium', 0.5);
    }

    public static function clamp(float $value): float
    {
        return max(0.0, min(1.0, round($value, 4)));
    }

    public static function isHigh(float $value): bool
    {
        return self::clamp($value) >= self::highThreshold();
    }

    public static function isMediumOrHigher(float $value): bool
    {
        return self::clamp($value) >= self::mediumThreshold();
    }

    public static function fromLabel(?string $label, ?float $numeric = null): float
    {
        if ($numeric !== null) {
            return self::clamp($numeric);
        }

        return match (mb_strtolower(trim((string) $label))) {
            'high', 'explicit' => (float) config('knowledge.confidence.explicit_extraction', 0.85),
            'medium' => 0.65,
            'low', 'inference', 'inferred' => (float) config('knowledge.confidence.inference', 0.35),
            default => (float) config('knowledge.confidence.inference', 0.35),
        };
    }

    public static function manual(): float
    {
        return (float) config('knowledge.confidence.manual', 1.0);
    }

    public static function deterministic(): float
    {
        return (float) config('knowledge.confidence.deterministic', 0.95);
    }
}
