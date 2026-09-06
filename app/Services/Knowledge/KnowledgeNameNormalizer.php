<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Str;

final class KnowledgeNameNormalizer
{
    public static function name(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim($normalized);

        if ($normalized === '') {
            $normalized = Str::slug(mb_substr($name, 0, 80)) ?: 'entity';
        }

        return mb_substr($normalized, 0, 180);
    }

    public static function displayName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, 180);
    }

    public static function summary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $summary = trim((string) preg_replace('/\s+/u', ' ', $summary));

        if ($summary === '') {
            return null;
        }

        return mb_substr($summary, 0, max(40, (int) config('knowledge.max_summary_chars', 400)));
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function boundMetadata(array $metadata): array
    {
        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $max = max(200, (int) config('knowledge.max_metadata_chars', 1200));

        if (mb_strlen($encoded) <= $max) {
            return $metadata;
        }

        return ['truncated' => true];
    }
}
