<?php

namespace App\Services\Users;

use App\Models\User;
use RuntimeException;

final class AccessCodeGenerator
{
    public const OWNER_CODE = '2000';

    private const CODE_LENGTH = 6;

    private const MAX_ATTEMPTS = 25;

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->randomNumericCode();

            if ($this->isReserved($code)) {
                continue;
            }

            if (! User::query()->where('access_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique access code.');
    }

    public function isReserved(string $code): bool
    {
        return $code === self::OWNER_CODE;
    }

    private function randomNumericCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
