<?php

namespace App\Enums;

enum VoiceOrigin: string
{
    case Web = 'web';
    case Desktop = 'desktop';
    case Mobile = 'mobile';

    public static function normalize(mixed $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'desktop' => self::Desktop,
            'mobile' => self::Mobile,
            default => self::Web,
        };
    }
}
