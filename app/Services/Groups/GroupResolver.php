<?php

namespace App\Services\Groups;

use App\Models\TelegramGroup;
use App\Services\Memory\MemoryKeyNormalizer;
use Illuminate\Support\Collection;

final class GroupResolver
{
    /**
     * @param  Collection<int, TelegramGroup>  $pool
     * @return array{match: TelegramGroup}|array{candidates: list<TelegramGroup>}|array{empty: true}
     */
    public function resolve(Collection $pool, string $hint): array
    {
        $hint = trim($hint);

        if ($hint === '' || $pool->isEmpty()) {
            return ['empty' => true];
        }

        $exact = $pool->filter(static fn (TelegramGroup $group): bool => (string) $group->title === $hint)->values();

        if ($exact->count() === 1) {
            return ['match' => $exact->first()];
        }

        if ($exact->count() > 1) {
            return ['candidates' => $exact->all()];
        }

        $normalized = GroupTitleNormalizer::normalize($hint);

        $normalizedMatches = $pool->filter(
            static fn (TelegramGroup $group): bool => GroupTitleNormalizer::normalize($group->title) === $normalized,
        )->values();

        if ($normalizedMatches->count() === 1) {
            return ['match' => $normalizedMatches->first()];
        }

        if ($normalizedMatches->count() > 1) {
            return ['candidates' => $normalizedMatches->all()];
        }

        $like = '%'.MemoryKeyNormalizer::escapeLike($normalized).'%';
        $fuzzy = $pool->filter(function (TelegramGroup $group) use ($normalized, $like): bool {
            $title = GroupTitleNormalizer::normalize($group->title);

            return $title !== '' && (str_contains($title, $normalized) || $this->wildcardContains($title, $like));
        })->values();

        if ($fuzzy->count() === 1) {
            return ['match' => $fuzzy->first()];
        }

        if ($fuzzy->count() > 1) {
            return ['candidates' => $fuzzy->all()];
        }

        return ['empty' => true];
    }

    private function wildcardContains(string $title, string $like): bool
    {
        return str_contains($title, trim($like, '%'));
    }
}
