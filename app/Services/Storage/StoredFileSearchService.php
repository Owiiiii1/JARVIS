<?php

namespace App\Services\Storage;

use App\Enums\StoredFileStatus;
use App\Models\StoredFile;
use App\Models\StoredFileChunk;
use App\Models\User;

final class StoredFileSearchService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function searchFiles(User $user, string $query, ?string $extension = null, int $limit = 0): array
    {
        $limit = $limit > 0 ? min($limit, StoredFileConfig::searchResultLimit()) : StoredFileConfig::searchResultLimit();
        $builder = StoredFile::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->where('status', StoredFileStatus::Ready)
            ->orderByDesc('uploaded_at');

        $needle = trim($query);

        if ($needle !== '') {
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $builder->where(function ($query) use ($like): void {
                $query->where('display_name', 'like', $like)
                    ->orWhere('normalized_name', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('extension', 'like', $like);
            });
        }

        if (is_string($extension) && $extension !== '') {
            $builder->where('extension', strtolower($extension));
        }

        return $builder->limit($limit)
            ->get()
            ->map(fn (StoredFile $file): array => $this->fileCard($file))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchContents(User $user, StoredFile $file, string $query, int $limit = 0): array
    {
        if (! $this->isOwnedReady($user, $file)) {
            return [];
        }
        $limit = $limit > 0 ? min($limit, StoredFileConfig::maxChunksPerToolResult()) : StoredFileConfig::maxChunksPerToolResult();
        $needle = trim($query);

        if ($needle === '') {
            return [];
        }

        $like = '%'.addcslashes($needle, '%_\\').'%';
        $excerpt = StoredFileConfig::maxExcerptChars();
        $budget = StoredFileConfig::maxToolChars();
        $used = 0;
        $results = [];

        $chunks = StoredFileChunk::query()
            ->where('stored_file_id', $file->id)
            ->where('content', 'like', $like)
            ->orderBy('chunk_index')
            ->limit($limit)
            ->get();

        foreach ($chunks as $chunk) {
            $content = $this->excerptAround((string) $chunk->content, $needle, $excerpt);
            $used += mb_strlen($content);

            if ($used > $budget && $results !== []) {
                break;
            }

            $results[] = [
                'chunk_index' => $chunk->chunk_index,
                'excerpt' => $content,
                'char_start' => $chunk->char_start,
                'char_end' => $chunk->char_end,
                'truncated' => mb_strlen((string) $chunk->content) > mb_strlen($content),
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readChunks(User $user, StoredFile $file, int $start, int $count): array
    {
        if (! $this->isOwnedReady($user, $file)) {
            return [];
        }
        $start = max(0, $start);
        $count = max(1, min(StoredFileConfig::maxChunksPerToolResult(), $count));
        $budget = StoredFileConfig::maxToolChars();
        $used = 0;
        $results = [];

        $chunks = StoredFileChunk::query()
            ->where('stored_file_id', $file->id)
            ->where('chunk_index', '>=', $start)
            ->orderBy('chunk_index')
            ->limit($count)
            ->get();

        foreach ($chunks as $chunk) {
            $content = (string) $chunk->content;
            $truncated = false;

            if ($used + mb_strlen($content) > $budget) {
                $remain = max(80, $budget - $used);
                $content = mb_substr($content, 0, $remain);
                $truncated = true;
            }

            $used += mb_strlen($content);
            $results[] = [
                'chunk_index' => $chunk->chunk_index,
                'content' => $content,
                'char_start' => $chunk->char_start,
                'char_end' => $chunk->char_end,
                'truncated' => $truncated,
            ];

            if ($truncated) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function fileCard(StoredFile $file): array
    {
        return [
            'public_id' => $file->public_id,
            'display_name' => $file->display_name,
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'status' => $file->status->value,
            'uploaded_at' => optional($file->uploaded_at)?->toIso8601String(),
            'summary' => $this->bounded($file->summary, 280),
            'chunk_count' => $file->chunk_count,
            'extracted_chars' => $file->extracted_chars,
        ];
    }

    private function excerptAround(string $content, string $needle, int $max): string
    {
        $pos = mb_stripos($content, $needle);
        $length = mb_strlen($content);

        if ($pos === false) {
            return $this->bounded($content, $max);
        }

        $pad = (int) floor(($max - mb_strlen($needle)) / 2);
        $start = max(0, $pos - $pad);
        $excerpt = mb_substr($content, $start, $max);

        if ($start > 0) {
            $excerpt = '…'.$excerpt;
        }

        if ($start + $max < $length) {
            $excerpt .= '…';
        }

        return $excerpt;
    }

    private function bounded(?string $text, int $max): ?string
    {
        if (! is_string($text) || $text === '') {
            return $text;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private function isOwnedReady(User $user, StoredFile $file): bool
    {
        return (int) $file->user_id === (int) $user->id
            && ! $file->isDeleted()
            && $file->status === StoredFileStatus::Ready;
    }
}
