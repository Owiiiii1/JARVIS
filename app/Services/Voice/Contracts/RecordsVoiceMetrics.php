<?php

namespace App\Services\Voice\Contracts;

interface RecordsVoiceMetrics
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $event, array $context = []): void;
}
