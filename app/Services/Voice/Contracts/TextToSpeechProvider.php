<?php

namespace App\Services\Voice\Contracts;

use App\Services\Voice\DTO\SynthesizedSpeech;

interface TextToSpeechProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function synthesize(string $text, ?string $voiceId = null): SynthesizedSpeech;
}
