<?php

namespace App\Services\Synthesis;

final class CommitmentLanguage
{
    public static function isExplicit(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return false;
        }

        if (self::isVague($normalized)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(i(?:\'ll| will)|we will|promise[ds]?|commit(?:ted)?|пришлю|пришл[её]м|обещаю|обещал[аи]?|обязался|will (?:send|prepare|deliver|share|reply)|отправлю|сделаю до)\b/u',
            $normalized,
        );
    }

    public static function isVague(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return (bool) preg_match(
            '/\b(надо бы|может быть|maybe|probably|possibly|когда-нибудь|стоит (?:бы )?|would be nice|наверное|возможно стоит)\b/u',
            $normalized,
        );
    }

    public static function actorIsUser(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return (bool) preg_match('/\b(я |i(?:\'ll| will)|мы |we will|отправлю|пришлю|сделаю)\b/u', $normalized);
    }
}
