<?php

namespace App\Services\Voice;

use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use Illuminate\Support\Facades\Log;

final class VoiceMetricsLogger implements RecordsVoiceMetrics
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = []): void
    {
        unset(
            $context['audio'],
            $context['bytes'],
            $context['transcript'],
            $context['text'],
            $context['body'],
            $context['assistant'],
            $context['prompt'],
            $context['api_key'],
            $context['secret'],
        );

        Log::info('voice.'.$event, $context);
    }
}
