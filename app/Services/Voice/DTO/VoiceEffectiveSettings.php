<?php

namespace App\Services\Voice\DTO;

use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;

final readonly class VoiceEffectiveSettings
{
    public function __construct(
        public VoiceSttProvider $sttProvider,
        public VoiceTtsProvider $ttsProvider,
        public bool $spokenStyleEnabled,
        public string $spokenStyleHint,
        public ?string $elevenLabsVoiceId,
        public string $sttModel = '',
    ) {}
}
