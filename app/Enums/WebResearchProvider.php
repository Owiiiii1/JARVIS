<?php

namespace App\Enums;

enum WebResearchProvider: string
{
    case GeminiGoogle = 'gemini_google';
    case Tavily = 'tavily';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::GeminiGoogle => 'Gemini Google Search',
            self::Tavily => 'Tavily',
            self::Disabled => 'Disabled',
        };
    }

    public function workspaceLabel(): string
    {
        return match ($this) {
            self::GeminiGoogle => 'Web Search · Google',
            self::Tavily => 'Web Search · Tavily',
            self::Disabled => 'Web Search · Disabled',
        };
    }

    public static function normalize(mixed $value): self
    {
        $name = strtolower(trim((string) $value));

        return match ($name) {
            'gemini_google', 'gemini', 'google' => self::GeminiGoogle,
            'tavily' => self::Tavily,
            'disabled', 'null', 'none', 'off' => self::Disabled,
            default => self::Disabled,
        };
    }
}
