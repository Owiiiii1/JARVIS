<?php

namespace App\Services\Storage;

final class StoredFileChunker
{
    /**
     * @return list<array{index: int, content: string, char_start: int, char_end: int, token_estimate: int}>
     */
    public function chunk(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $size = mb_strlen($text);
        $window = StoredFileConfig::chunkChars();
        $overlap = StoredFileConfig::chunkOverlapChars();
        $chunks = [];
        $start = 0;
        $index = 0;

        while ($start < $size) {
            $end = min($size, $start + $window);

            if ($end < $size) {
                $slice = mb_substr($text, $start, $end - $start);
                $break = $this->lastBreak($slice);

                if ($break !== null && $break > (int) ($window * 0.4)) {
                    $end = $start + $break;
                }
            }

            $content = mb_substr($text, $start, $end - $start);
            $chunks[] = [
                'index' => $index,
                'content' => $content,
                'char_start' => $start,
                'char_end' => $end,
                'token_estimate' => (int) max(1, ceil(mb_strlen($content) / 4)),
            ];

            $index++;

            if ($end >= $size) {
                break;
            }

            $start = max($start + 1, $end - $overlap);
        }

        return $chunks;
    }

    private function lastBreak(string $slice): ?int
    {
        $candidates = [
            mb_strrpos($slice, "\n\n"),
            mb_strrpos($slice, "\n"),
        ];

        foreach ($candidates as $pos) {
            if (is_int($pos) && $pos > 0) {
                return $pos + 1;
            }
        }

        return null;
    }
}
