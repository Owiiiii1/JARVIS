<?php

namespace App\Services\Groups;

final class GroupMessageChunker
{
    /**
     * @param  list<array{id: int, line: string, chars: int}>  $lines
     * @return list<list<array{id: int, line: string, chars: int}>>
     */
    public function chunk(array $lines): array
    {
        $maxMessages = max(1, (int) config('group_analysis.max_messages_per_chunk'));
        $maxChars = max(500, (int) config('group_analysis.max_chars_per_chunk'));
        $maxChunks = max(1, (int) config('group_analysis.max_chunks_per_run'));
        $chunks = [];
        $current = [];
        $chars = 0;

        foreach ($lines as $line) {
            $nextCount = count($current) + 1;
            $nextChars = $chars + $line['chars'] + 1;

            if ($current !== [] && ($nextCount > $maxMessages || $nextChars > $maxChars)) {
                $chunks[] = $current;

                if (count($chunks) >= $maxChunks) {
                    return $chunks;
                }

                $current = [];
                $chars = 0;
            }

            $current[] = $line;
            $chars += $line['chars'] + 1;
        }

        if ($current !== [] && count($chunks) < $maxChunks) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
