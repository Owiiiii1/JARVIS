<?php

namespace App\Services\Groups;

final class GroupTitleNormalizer
{
    public static function normalize(?string $title): string
    {
        $normalized = mb_strtolower(trim((string) $title));
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return mb_substr($normalized, 0, 120);
    }
}
