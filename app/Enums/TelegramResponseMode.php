<?php

namespace App\Enums;

enum TelegramResponseMode: string
{
    case Text = 'text';
    case Voice = 'voice';
    case Auto = 'auto';

    public static function default(): self
    {
        return self::Text;
    }

    public static function tryFromInput(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
