<?php

namespace App\Services\Voice\Contracts;

interface ResolvesTelegramTtsSpeed
{
    public function telegramTtsSpeed(): float;
}
