<?php

namespace App\Services\Watchers;

use App\Enums\KnowledgeSourceType;
use Illuminate\Support\Str;

final class WatcherSupport
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function boundMetadata(array $metadata): array
    {
        $blocked = ['token', 'access_token', 'refresh_token', 'password', 'secret', 'credentials', 'raw', 'body', 'payload'];
        foreach ($blocked as $key) {
            unset($metadata[$key]);
        }

        $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $max = max(200, (int) config('watchers.max_metadata_chars', 1200));

        if (mb_strlen($encoded) <= $max) {
            return $metadata;
        }

        return ['truncated' => true];
    }

    public static function fingerprint(string ...$parts): string
    {
        return hash('sha256', implode('|', array_map(static fn (string $part): string => trim($part), $parts)));
    }

    public static function summary(?string $text, ?int $max = null): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return self::clip($text, $max);
    }

    public static function clip(?string $text, ?int $max = null): string
    {
        $text = trim((string) $text);
        $limit = $max ?? max(40, (int) config('watchers.max_summary_chars', 400));

        return mb_substr($text, 0, $limit);
    }

    public static function displayName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_substr($name, 0, max(20, (int) config('watchers.max_name_chars', 180)));
    }

    public static function knowledgeSource(): KnowledgeSourceType
    {
        return KnowledgeSourceType::ToolResult;
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }
}
