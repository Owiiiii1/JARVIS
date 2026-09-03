<?php

namespace App\Services\Projects;

final class ProjectNameNormalizer
{
    public static function normalize(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return mb_substr($normalized, 0, 120);
    }
}
