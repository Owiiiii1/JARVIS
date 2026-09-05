<?php

namespace App\Services\Voice\Contracts;

use App\Services\Voice\DTO\SynthesizedSpeech;

interface SpeechSynthesizer
{
    public function isConfigured(): bool;

    public function synthesize(string $text, ?string $voiceId = null): SynthesizedSpeech;
}
