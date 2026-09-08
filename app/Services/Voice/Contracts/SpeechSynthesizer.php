<?php

namespace App\Services\Voice\Contracts;

use App\Services\Voice\DTO\SynthesizedSpeech;
use App\Services\Voice\DTO\TextToSpeechOptions;

interface SpeechSynthesizer
{
    public function isConfigured(): bool;

    public function synthesize(string $text, ?string $voiceId = null, ?TextToSpeechOptions $options = null): SynthesizedSpeech;
}
