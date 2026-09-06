<?php

namespace App\Enums;

enum CommitmentStatus: string
{
    case Open = 'open';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public static function tryFromLoose(mixed $value): ?self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        return match ($raw) {
            'open', 'pending' => self::Open,
            'fulfilled', 'done', 'completed' => self::Fulfilled,
            'cancelled', 'canceled' => self::Cancelled,
            'superseded', 'replaced' => self::Superseded,
            default => self::tryFrom($raw),
        };
    }
}
