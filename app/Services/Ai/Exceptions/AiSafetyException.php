<?php

namespace App\Services\Ai\Exceptions;

final class AiSafetyException extends AiProviderException
{
    public function __construct(
        public readonly string $reason = 'SAFETY',
    ) {
        parent::__construct('AI response was blocked by the provider safety policy.');
    }
}
