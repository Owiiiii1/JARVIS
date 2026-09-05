<?php

namespace App\Services\Voice\Contracts;

interface StoresEphemeralVoiceAudio
{
    public function putBytes(string $relativePath, string $bytes): string;

    public function absolutePath(string $relativePath): string;

    public function deleteRelative(string $relativePath): void;
}
