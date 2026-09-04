<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Log;

final class VoiceMetricsLogger
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
