<?php

namespace App\Console\Commands;

use App\Services\Voice\VoiceRuntimeService;
use App\Services\Voice\VoiceTempAudioStore;
use Illuminate\Console\Command;

class CleanupVoiceTempCommand extends Command
{
    protected $signature = 'jarvis:voice:cleanup-temp';

    protected $description = 'Expire inactive voice sessions and delete stale temporary voice audio. Does not delete transcripts or messages.';

    public function handle(VoiceTempAudioStore $audio, VoiceRuntimeService $runtime): int
    {
        $expired = $runtime->expireStale();
        $deleted = $audio->purgeStale();
        $this->info("Expired {$expired} voice session(s); deleted {$deleted} temporary audio file(s).");

        return self::SUCCESS;
    }
}
