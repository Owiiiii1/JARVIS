<?php

namespace App\Services\Ai;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;

final class ToolCallFingerprint
{
    /**
     * @var list<string>
     */
    private const IGNORED_ARGUMENT_KEYS = [
        'authorized', 'confirmation', 'user_id', 'integration_account_id',
    ];

    /**
     * @var list<string>
     */
    private const CONTENT_KEYS = [
        'content', 'excerpt', 'body', 'text', 'html', 'diff', 'patch', 'raw',
        'chunks', 'snippet', 'messages', 'events', 'files',
    ];

    public static function forCall(ToolCall $call): string
    {
        return self::hash($call->name.'|'.json_encode(self::normalize($call->arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function forResult(ToolResult $result): string
    {
        return self::payload($result->name, $result->payload, $result->success);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payload(string $name, array $payload, ?bool $success = null): string
    {
        $semantic = self::semanticPayload($payload);

        if ($success !== null) {
            $semantic['success'] = $success;
        }

        return self::hash($name.'|'.json_encode($semantic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function normalize(array $arguments): array
    {
        $clean = [];

        foreach ($arguments as $key => $value) {
            if (in_array((string) $key, self::IGNORED_ARGUMENT_KEYS, true)) {
                continue;
            }

            $clean[(string) $key] = is_array($value) ? self::normalizeArray($value) : $value;
        }

        ksort($clean);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function semanticPayload(array $payload): array
    {
        $keep = [];

        foreach ([
            'success', 'error', 'file_id', 'query', 'count', 'truncated', 'id',
            'start', 'end', 'offset', 'limit', 'chunk_start', 'chunk_end',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $keep[$key] = $payload[$key];
            }
        }

        if (isset($payload['file']) && is_array($payload['file'])) {
            $keep['file_id'] = $payload['file']['public_id'] ?? $payload['file']['id'] ?? $keep['file_id'] ?? null;
        }

        if (isset($payload['chunks']) && is_array($payload['chunks'])) {
            $indexes = [];

            foreach ($payload['chunks'] as $chunk) {
                if (is_array($chunk) && array_key_exists('index', $chunk)) {
                    $indexes[] = $chunk['index'];
                }
            }

            $keep['chunk_count'] = count($payload['chunks']);
            $keep['chunk_indexes'] = $indexes;
        }

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::CONTENT_KEYS, true)) {
                if (is_array($value)) {
                    $keep[(string) $key.'_count'] = count($value);
                } elseif (is_string($value)) {
                    $keep[(string) $key.'_len'] = mb_strlen($value);
                }

                continue;
            }
        }

        ksort($keep);

        return $keep;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function normalizeArray(array $value): array
    {
        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = is_array($item) ? self::normalizeArray($item) : $item;
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
