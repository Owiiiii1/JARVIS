<?php

namespace App\Services\Ai\Exceptions;

final class AiEmptyResponseException extends AiProviderException
{
    public function __construct()
    {
        parent::__construct('AI provider returned an empty assistant response.');
    }
}
