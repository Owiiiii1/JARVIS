<?php

namespace App\Services\Memory;

use Illuminate\Support\Str;

final class MemoryKeyNormalizer
{
    public static function topicName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return mb_substr($normalized, 0, 120);
    }

    public static function memoryKey(?string $key, string $content): string
    {
        $source = trim((string) $key);

        if ($source === '') {
            $source = $content;
        }

        $source = mb_strtolower($source);
        $source = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $source);
        $source = (string) preg_replace('/\s+/u', ' ', $source);
        $source = trim($source);

        if ($source === '') {
            $source = Str::slug(mb_substr($content, 0, 80)) ?: 'memory';
        }

        return mb_substr($source, 0, 180);
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text, int $minLength = 3): array
    {
        $text = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts)) {
            return [];
        }

        $tokens = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) < $minLength) {
                continue;
            }

            $tokens[$part] = $part;
        }

        return array_values($tokens);
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
