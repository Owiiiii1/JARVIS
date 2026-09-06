<?php

namespace App\Services\Reminders;

final class VapidConfig
{
    public static function publicKey(): ?string
    {
        $key = trim((string) config('reminders.vapid.public_key'));

        return $key !== '' ? $key : null;
    }

    public static function privateKey(): ?string
    {
        $key = trim((string) config('reminders.vapid.private_key'));

        return $key !== '' ? $key : null;
    }

    public static function subject(): string
    {
        $subject = trim((string) config('reminders.vapid.subject'));

        if ($subject === '') {
            return 'mailto:jarvis@localhost';
        }

        return $subject;
    }

    public static function isConfigured(): bool
    {
        return self::publicKey() !== null && self::privateKey() !== null;
    }
}
