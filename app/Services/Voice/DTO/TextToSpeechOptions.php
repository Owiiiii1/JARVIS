<?php

namespace App\Services\Voice\DTO;

final readonly class TextToSpeechOptions
{
    /**
     * @param  array<string, mixed>  $voiceSettings
     */
    public function __construct(
        public ?float $speed = null,
        public array $voiceSettings = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function withSpeed(float $speed): self
    {
        return new self(speed: $speed);
    }
}
