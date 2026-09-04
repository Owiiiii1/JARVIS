<?php

namespace App\Enums;

enum VoiceSttProvider: string
{
    case None = 'none';
    case OpenAi = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not configured',
            self::OpenAi => 'OpenAI Whisper',
        };
    }

    public static function normalize(mixed $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'openai', 'whisper', 'openai_whisper' => self::OpenAi,
            default => self::None,
        };
    }
}
