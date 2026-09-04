<?php

namespace App\Enums;

enum VoiceSttProvider: string
{
    case None = 'none';
    case Gemini = 'gemini';
    case OpenAi = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Gemini => 'Gemini',
            self::OpenAi => 'OpenAI',
        };
    }

    public static function normalize(mixed $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'gemini', 'gemini_stt' => self::Gemini,
            'openai', 'whisper', 'openai_whisper' => self::OpenAi,
            default => self::None,
        };
    }
}
