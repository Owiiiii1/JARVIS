<?php

namespace App\Services\Voice\DTO;

final readonly class SynthesizedSpeech
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $voiceId,
        public ?int $sampleRate = null,
        public ?float $durationSeconds = null,
        public array $providerMetadata = [],
    ) {}
}
