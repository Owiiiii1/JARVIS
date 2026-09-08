<?php

namespace App\Services\Watchers;

final class ProactiveCheckIntent
{
    public static function userSelfReminder(string $text): bool
    {
        $normalized = mb_strtolower(trim($text));

        return preg_match('/\bнапомни(?:те)?\b|\bremind(?:\s+me)?\b/u', $normalized) === 1;
    }

    public static function mentionsMail(string $text): bool
    {
        return preg_match('/почт|gmail|inbox|письм|email|e-mail|мейл/u', mb_strtolower($text)) === 1;
    }

    public static function jarvisPerformsCheck(string $text): bool
    {
        return preg_match(
            '/провер(?:яй|ять|ь)|посмотр(?:и|еть)|след(?:и|ить)|монитор|сообща(?:й|ть).{0,48}нов|присыл(?:ай|ать).{0,24}сводк|рассказ(?:ывай|ывать|жи).{0,48}нов|what.?s new|check.{0,24}(?:mail|inbox|gmail)|watch.{0,24}(?:mail|inbox)/u',
            mb_strtolower($text),
        ) === 1;
    }

    public static function jarvisShouldMonitorMail(string $text): bool
    {
        return self::mentionsMail($text)
            && self::jarvisPerformsCheck($text)
            && ! self::userSelfReminder($text);
    }
}
