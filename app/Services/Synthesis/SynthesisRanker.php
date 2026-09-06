<?php

namespace App\Services\Synthesis;

use App\Services\Synthesis\DTO\SynthesisItem;

final class SynthesisRanker
{
    /**
     * @param  list<SynthesisItem>  $items
     * @return list<SynthesisItem>
     */
    public function sort(array $items, int $limit): array
    {
        usort($items, static fn (SynthesisItem $left, SynthesisItem $right): int => $right->score <=> $left->score);

        return array_slice(array_values($items), 0, max(1, $limit));
    }
}
