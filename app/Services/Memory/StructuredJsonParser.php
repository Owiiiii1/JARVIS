<?php

namespace App\Services\Memory;

use App\Services\Memory\Exceptions\MemoryAnalysisException;

final class StructuredJsonParser
{
    /**
     * @return array<string, mixed>
     */
    public static function objectFromText(string $text): array
    {
        $payload = trim($text);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $payload, $matches) === 1) {
            $payload = $matches[1];
        } elseif (preg_match('/\{.*\}/s', $payload, $matches) === 1) {
            $payload = $matches[0];
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new MemoryAnalysisException('Analysis AI did not return a JSON object.');
        }

        return $decoded;
    }
}
