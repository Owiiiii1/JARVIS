<?php

namespace App\Services\Voice\Contracts;

/**
 * Future duplex adapter (telephony / vendor realtime). Not the M23 Core.
 * Canonical ports remain SpeechToTextProvider and TextToSpeechProvider.
 */
interface RealtimeDuplexSpeechProvider
{
    public function name(): string;

    public function isConfigured(): bool;
}
