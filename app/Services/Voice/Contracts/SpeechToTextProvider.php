<?php

namespace App\Services\Voice\Contracts;

use App\Services\Voice\DTO\SpeechTranscript;
use App\Services\Voice\DTO\VoiceAudioChunk;

interface SpeechToTextProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function transcribe(VoiceAudioChunk $chunk, ?string $language = null): SpeechTranscript;
}
