<?php

namespace App\Enums;

enum VoiceTtsProvider: string
{
    case None = 'none';
    case ElevenLabs = 'elevenlabs';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not configured',
            self::ElevenLabs => 'ElevenLabs',
        };
    }

    public static function normalize(mixed $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'elevenlabs', 'eleven' => self::ElevenLabs,
            default => self::None,
        };
    }
}
