<?php

namespace App\Services\Voice\DTO;

final readonly class SpeechTranscript
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public string $text,
        public bool $isFinal,
        public ?string $language = null,
        public ?float $confidence = null,
        public array $providerMetadata = [],
    ) {}
}
